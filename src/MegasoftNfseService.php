<?php

namespace R2soft\MegasoftNfse;

/**
 * Orquestrador do fluxo NFSe Megasoft.
 * Sequência: MegasoftXmlBuilder → MegasoftXmlSigner → MegasoftSoapClient
 */
class MegasoftNfseService
{
    private MegasoftXmlBuilder $builder;
    private MegasoftXmlSigner  $signer;

    public function __construct()
    {
        $this->builder = new MegasoftXmlBuilder();
        $this->signer  = new MegasoftXmlSigner();
    }

    /**
     * Emite uma NFS-e.
     *
     * @param array $config  wsdl_url, certificado_conteudo (bytes do .pfx),
     *                       certificado_senha (decriptada),
     *                       ambiente (prod|hom|mock), wsdl_cache_dir (opcional)
     * @param array $dados   Ver MegasoftXmlBuilder::buildGerarNfseEnvio
     *
     * @return array {
     *   sucesso            bool
     *   numero_nfse        string|null
     *   codigo_verificacao string|null
     *   xml_envio          string
     *   xml_retorno        string|null
     *   mensagem           string|null
     * }
     */
    public function emitir(array $config, array $dados): array
    {
        $xmlAssinado = '';
        try {
            $rpsId       = MegasoftXmlSigner::gerarRpsId();
            $xmlSem      = $this->builder->buildGerarNfseEnvio($dados, $rpsId);
            $xmlAssinado = $this->signer->sign(
                $xmlSem,
                $config['certificado_conteudo'] ?? '',
                $config['certificado_senha'] ?? '',
                $rpsId
            );

            $client     = $this->makeSoapClient($config);
            $xmlRetorno = $client->call('GerarNfse', $xmlAssinado);

            $resultado = $this->parseRetornoEmissao($xmlRetorno);
            $resultado['xml_envio']   = $xmlAssinado;
            $resultado['xml_retorno'] = $xmlRetorno;

            return $resultado;

        } catch (MegasoftNfseException $e) {
            return [
                'sucesso'            => false,
                'numero_nfse'        => null,
                'codigo_verificacao' => null,
                'xml_envio'          => $xmlAssinado,
                'xml_retorno'        => $e->getXmlResposta(),
                'mensagem'           => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancela uma NFS-e.
     *
     * @param array $config  wsdl_url, certificado_conteudo, certificado_senha, ambiente
     * @param array $dados   numero_nfse, codigo_municipio, prestador_cpf_cnpj,
     *                       prestador_tipo, prestador_im, codigo_cancelamento
     * @return array { sucesso, xml_retorno, mensagem }
     */
    public function cancelar(array $config, array $dados): array
    {
        try {
            $xml        = $this->builder->buildCancelarNfseEnvio($dados);
            $client     = $this->makeSoapClient($config);
            $xmlRetorno = $client->call('CancelarNfse', $xml);

            if (empty($xmlRetorno)) {
                return [
                    'sucesso'     => false,
                    'xml_retorno' => null,
                    'mensagem'    => 'Resposta vazia do webservice — cancelamento não confirmado.',
                ];
            }

            $temErro = $this->temErro($xmlRetorno);
            $temConfirmacao = str_contains($xmlRetorno, '<Confirmacao>')
                           || str_contains($xmlRetorno, '<DataHora>')
                           || str_contains($xmlRetorno, '<NfseCancelamento>');

            $sucesso = !$temErro && $temConfirmacao;
            $mensagem = $temErro
                ? $this->extrairMensagemErro($xmlRetorno)
                : ($temConfirmacao ? null : 'Retorno do webservice sem confirmação de cancelamento.');

            return [
                'sucesso'     => $sucesso,
                'xml_retorno' => $xmlRetorno,
                'mensagem'    => $mensagem,
            ];

        } catch (MegasoftNfseException $e) {
            return [
                'sucesso'     => false,
                'xml_retorno' => null,
                'mensagem'    => $e->getMessage(),
            ];
        }
    }

    /**
     * Consulta NFS-e por número de RPS (operação ConsultarNfsePorRps do WSDL).
     *
     * Serve para descobrir se um RPS cuja emissão ficou sem resposta (timeout) virou nota.
     *
     * @param array $config  wsdl_url, certificado_conteudo, certificado_senha, ambiente
     * @param array $dados   numero_rps, prestador_cpf_cnpj, prestador_tipo, prestador_im
     * @return array {
     *   sucesso            bool       a consulta teve resposta conclusiva (achou a nota ou o RPS não existe)
     *   encontrada         bool|null  true = a nota existe; false = RPS não encontrado; null = sem resposta conclusiva
     *   cancelada          bool       a nota encontrada está cancelada
     *   numero_nfse        string|null
     *   codigo_verificacao string|null
     *   data_emissao       string|null
     *   xml_retorno        string|null
     *   mensagem           string|null
     * }
     */
    public function consultar(array $config, array $dados): array
    {
        try {
            $xml        = $this->builder->buildConsultarNfseRps($dados);
            $client     = $this->makeSoapClient($config);
            $xmlRetorno = $client->call('ConsultarNfsePorRps', $xml);

            $resultado = $this->parseRetornoConsulta($xmlRetorno);
            $resultado['xml_retorno'] = $xmlRetorno;

            return $resultado;

        } catch (MegasoftNfseException $e) {
            return $this->consultaSemResposta($e->getMessage(), null);
        }
    }

    /**
     * Lê a resposta de ConsultarNfsePorRps. Formatos observados em Jaraguá/GO:
     * - nota: ConsultarNfseRpsResposta/CompNfse/Nfse/InfNfse/{Numero, CodigoVerificacao, DataEmissao}
     *   (o XML tem outros <Numero>: endereço e IdentificacaoRps; só o filho direto de InfNfse é a nota)
     * - RPS inexistente: ListaMensagemRetorno/MensagemRetorno {Codigo L000, Mensagem "RPS não encontrada"}
     */
    public function parseRetornoConsulta(string $xmlRetorno): array
    {
        if (trim($xmlRetorno) === '') {
            return $this->consultaSemResposta('Resposta vazia do webservice.', $xmlRetorno);
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadXML($xmlRetorno, 1 | 32 | 64); // LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING
        libxml_clear_errors();

        if (!$doc->documentElement) {
            return $this->consultaSemResposta('Resposta inválida: ' . substr(strip_tags($xmlRetorno), 0, 300), $xmlRetorno);
        }

        $infNfse = $doc->getElementsByTagName('InfNfse')->item(0);
        if ($infNfse) {
            $filho = static function (\DOMElement $pai, string $nome): ?string {
                foreach ($pai->childNodes as $n) {
                    if ($n instanceof \DOMElement && $n->localName === $nome) {
                        return trim($n->textContent);
                    }
                }
                return null;
            };
            $numero = $filho($infNfse, 'Numero');
            if ($numero !== null && $numero !== '') {
                return [
                    'sucesso'            => true,
                    'encontrada'         => true,
                    'cancelada'          => $doc->getElementsByTagName('NfseCancelamento')->length > 0,
                    'numero_nfse'        => $numero,
                    'codigo_verificacao' => $filho($infNfse, 'CodigoVerificacao'),
                    'data_emissao'       => $filho($infNfse, 'DataEmissao'),
                    'xml_retorno'        => $xmlRetorno,
                    'mensagem'           => null,
                ];
            }
        }

        $mensagens = [];
        foreach ($doc->getElementsByTagName('MensagemRetorno') as $item) {
            $codigo = $item->getElementsByTagName('Codigo')->item(0)?->textContent ?? '';
            $desc   = $item->getElementsByTagName('Mensagem')->item(0)?->textContent ?? '';
            $mensagens[] = trim("[{$codigo}] {$desc}");
        }
        $mensagem = $mensagens ? implode('; ', $mensagens) : 'Número da NFS-e não encontrado na resposta.';

        // O código (L000) é genérico em Jaraguá; o que identifica o caso é o texto.
        if (preg_match('/n[aã]o\s+encontrad/iu', $mensagem)) {
            return [
                'sucesso'            => true,
                'encontrada'         => false,
                'cancelada'          => false,
                'numero_nfse'        => null,
                'codigo_verificacao' => null,
                'data_emissao'       => null,
                'xml_retorno'        => $xmlRetorno,
                'mensagem'           => $mensagem,
            ];
        }

        return $this->consultaSemResposta($mensagem, $xmlRetorno);
    }

    private function consultaSemResposta(string $mensagem, ?string $xmlRetorno): array
    {
        return [
            'sucesso'            => false,
            'encontrada'         => null,
            'cancelada'          => false,
            'numero_nfse'        => null,
            'codigo_verificacao' => null,
            'data_emissao'       => null,
            'xml_retorno'        => $xmlRetorno,
            'mensagem'           => $mensagem,
        ];
    }

    private function makeSoapClient(array $config): MegasoftSoapClient
    {
        return new MegasoftSoapClient(
            $config['wsdl_url'],
            $config['ambiente'] ?? 'prod',
            30,
            $config['certificado_conteudo'] ?? null,
            $config['certificado_senha'] ?? null,
            $config['wsdl_cache_dir'] ?? null
        );
    }

    private function parseRetornoEmissao(string $xmlRetorno): array
    {
        if (empty($xmlRetorno)) {
            return ['sucesso' => false, 'numero_nfse' => null, 'codigo_verificacao' => null, 'mensagem' => 'Resposta vazia do webservice.'];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        @$doc->loadXML($xmlRetorno, 1 | 32 | 64); // LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING
        libxml_clear_errors();

        if (!$doc->documentElement) {
            $preview = substr(strip_tags($xmlRetorno), 0, 300);
            return ['sucesso' => false, 'numero_nfse' => null, 'codigo_verificacao' => null, 'mensagem' => 'Resposta inválida: ' . $preview];
        }

        $mensagensErro = $doc->getElementsByTagName('MensagemRetorno');
        if ($mensagensErro->length === 0) {
            $mensagensErro = $doc->getElementsByTagName('Erro');
        }
        if ($mensagensErro->length > 0) {
            $msgs = [];
            foreach ($mensagensErro as $item) {
                $codigo = $item->getElementsByTagName('Codigo')->item(0)?->textContent ?? '';
                $desc   = $item->getElementsByTagName('Mensagem')->item(0)?->textContent
                    ?? $item->getElementsByTagName('Descricao')->item(0)?->textContent
                    ?? '';
                $msgs[] = "[{$codigo}] {$desc}";
            }
            return ['sucesso' => false, 'numero_nfse' => null, 'codigo_verificacao' => null, 'mensagem' => implode('; ', $msgs)];
        }

        $numero = $doc->getElementsByTagName('Numero')->item(0)?->textContent;
        $codigo = $doc->getElementsByTagName('CodigoVerificacao')->item(0)?->textContent;

        return [
            'sucesso'            => ($numero !== null),
            'numero_nfse'        => $numero,
            'codigo_verificacao' => $codigo,
            'mensagem'           => $numero ? null : 'Número da NFS-e não encontrado na resposta.',
        ];
    }

    private function temErro(string $xml): bool
    {
        return str_contains($xml, '<Erro>') || str_contains($xml, '<ListaMensagemRetorno>');
    }

    private function extrairMensagemErro(string $xml): ?string
    {
        if (!$this->temErro($xml)) {
            return null;
        }
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return null;
        }
        return $doc->getElementsByTagName('Descricao')->item(0)?->textContent;
    }
}

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
     * @param array $config  wsdl_url, certificado_path, certificado_senha (decriptada),
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
                $config['certificado_path'],
                $config['certificado_senha'],
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
     * @param array $config  wsdl_url, certificado_path, certificado_senha, ambiente
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

            return [
                'sucesso'     => !$this->temErro($xmlRetorno),
                'xml_retorno' => $xmlRetorno,
                'mensagem'    => $this->extrairMensagemErro($xmlRetorno),
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
     * Consulta NFS-e por número de RPS.
     *
     * @param array $config  wsdl_url, certificado_path, certificado_senha, ambiente
     * @param array $dados   numero_rps, prestador_cpf_cnpj, prestador_tipo, prestador_im
     * @return array { sucesso, numero_nfse, codigo_verificacao, xml_retorno, mensagem }
     */
    public function consultar(array $config, array $dados): array
    {
        try {
            $xml        = $this->builder->buildConsultarNfseRps($dados);
            $client     = $this->makeSoapClient($config);
            $xmlRetorno = $client->call('ConsultarNfseRps', $xml);

            $resultado = $this->parseRetornoEmissao($xmlRetorno);
            $resultado['xml_retorno'] = $xmlRetorno;

            return $resultado;

        } catch (MegasoftNfseException $e) {
            return [
                'sucesso'            => false,
                'numero_nfse'        => null,
                'codigo_verificacao' => null,
                'xml_retorno'        => null,
                'mensagem'           => $e->getMessage(),
            ];
        }
    }

    private function makeSoapClient(array $config): MegasoftSoapClient
    {
        return new MegasoftSoapClient(
            $config['wsdl_url'],
            $config['ambiente'] ?? 'prod',
            30,
            $config['certificado_path'] ?? null,
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
        @$doc->loadXML($xmlRetorno, LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING);
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

<?php

namespace R2soft\MegasoftNfse;

class MegasoftXmlBuilder
{
    const NAMESPACE_XSD = 'http://megasoftarrecadanet.com.br/xsd/nfse_v01.xsd';

    /**
     * Monta o XML GerarNfseEnvio sem assinatura.
     *
     * @param array $dados {
     *   numero_rps, data_emissao, valor_servicos, discriminacao,
     *   codigo_tributacao, prestador_cpf_cnpj, prestador_tipo, prestador_im,
     *   tomador_cpf_cnpj, tomador_tipo, tomador_razao_social,
     *   tomador_endereco, tomador_numero, tomador_complemento,
     *   tomador_bairro, tomador_cep, tomador_cod_municipio,
     *   codigo_municipio, aliquota, iss_retido, exigibilidade,
     *   optante_simples_nacional, incentivo_fiscal, numero_nbs,
     *   valor_deducoes, valor_pis, valor_cofins, valor_inss,
     *   valor_ir, valor_csll, outras_retencoes,
     *   desconto_incondicionado, desconto_condicionado
     * }
     * @param string $rpsId  Ex: "pfx{uuid}" — atributo Id do <Rps> interno
     */
    public function buildGerarNfseEnvio(array $dados, string $rpsId): string
    {
        $d = $this->defaults($dados);

        $obrigatorios = [
            'numero_rps'           => 'Número do RPS',
            'data_emissao'         => 'Data de emissão',
            'valor_servicos'       => 'Valor dos serviços',
            'discriminacao'        => 'Discriminação do serviço',
            'codigo_tributacao'    => 'Código de tributação do município',
            'prestador_cpf_cnpj'   => 'CPF/CNPJ do prestador',
            'prestador_im'         => 'Inscrição Municipal do prestador',
            'tomador_cpf_cnpj'     => 'CPF/CNPJ do tomador',
            'tomador_razao_social' => 'Razão Social do tomador',
            'tomador_endereco'     => 'Endereço do tomador',
            'tomador_numero'       => 'Número do endereço do tomador',
            'tomador_bairro'       => 'Bairro do tomador',
            'tomador_cep'          => 'CEP do tomador',
        ];
        foreach ($obrigatorios as $campo => $label) {
            if (empty($d[$campo])) {
                throw new MegasoftNfseException("Campo obrigatório não preenchido: {$label} ({$campo}).");
            }
        }

        foreach (['codigo_municipio' => 'Cód. IBGE do município', 'tomador_cod_municipio' => 'Cód. IBGE do tomador'] as $campo => $label) {
            if (empty($d[$campo]) || !ctype_digit((string) $d[$campo])) {
                throw new MegasoftNfseException("Campo inválido: {$label} ({$campo}) deve ser numérico (7 dígitos).");
            }
        }

        $dataEmissao = strlen($d['data_emissao']) === 10
            ? $d['data_emissao'] . 'T00:00:00'
            : substr($d['data_emissao'], 0, 19);

        $prestadorTag = $d['prestador_tipo'] === 'CPF'
            ? "<Cpf>{$d['prestador_cpf_cnpj']}</Cpf>"
            : "<Cnpj>{$d['prestador_cpf_cnpj']}</Cnpj>";

        $tomadorTag = $d['tomador_tipo'] === 'CPF'
            ? "<Cpf>{$d['tomador_cpf_cnpj']}</Cpf>"
            : "<Cnpj>{$d['tomador_cpf_cnpj']}</Cnpj>";

        $complemento = !empty($d['tomador_complemento'])
            ? "<Complemento>" . $this->esc($d['tomador_complemento']) . "</Complemento>"
            : '';

        $numeroNbs = !empty($d['numero_nbs'])
            ? "<NumeroNbs>{$d['numero_nbs']}</NumeroNbs>"
            : '';

        $itemListaServico = !empty($d['item_lista_servico'])
            ? "<ItemListaServico>{$d['item_lista_servico']}</ItemListaServico>"
            : '';

        $codigoCnae = !empty($d['codigo_cnae'])
            ? "<CodigoCnae>{$d['codigo_cnae']}</CodigoCnae>"
            : '';

        $ns = self::NAMESPACE_XSD;

        return <<<XML
<GerarNfseEnvio xmlns="{$ns}">
   <Rps>
      <InfDeclaracaoPrestacaoServico>
         <Rps Id="{$rpsId}">
            <IdentificacaoRps>
               <Numero>{$d['numero_rps']}</Numero>
            </IdentificacaoRps>
            <DataEmissao>{$dataEmissao}</DataEmissao>
         </Rps>
         <Servico>
            <Valores>
               <ValorServicos>{$d['valor_servicos']}</ValorServicos>
               <ValorDeducoes>{$d['valor_deducoes']}</ValorDeducoes>
               <ValorPis>{$d['valor_pis']}</ValorPis>
               <ValorCofins>{$d['valor_cofins']}</ValorCofins>
               <ValorInss>{$d['valor_inss']}</ValorInss>
               <ValorIr>{$d['valor_ir']}</ValorIr>
               <ValorCsll>{$d['valor_csll']}</ValorCsll>
               <OutrasRetencoes>{$d['outras_retencoes']}</OutrasRetencoes>
               <Aliquota>{$d['aliquota']}</Aliquota>
               <DescontoIncondicionado>{$d['desconto_incondicionado']}</DescontoIncondicionado>
               <DescontoCondicionado>{$d['desconto_condicionado']}</DescontoCondicionado>
            </Valores>
            <IssRetido>{$d['iss_retido']}</IssRetido>
            {$itemListaServico}
            <CodigoMunicipio>{$d['codigo_municipio']}</CodigoMunicipio>
            <CodigoTributacaoMunicipio>{$d['codigo_tributacao']}</CodigoTributacaoMunicipio>
            {$codigoCnae}
            <Exigibilidade>{$d['exigibilidade']}</Exigibilidade>
            {$numeroNbs}
            <Discriminacao>{$this->esc($d['discriminacao'])}</Discriminacao>
         </Servico>
         <Prestador>
            <CpfCnpj>
               {$prestadorTag}
            </CpfCnpj>
            <InscricaoMunicipal>{$d['prestador_im']}</InscricaoMunicipal>
         </Prestador>
         <Tomador>
            <IdentificacaoTomador>
               <CpfCnpj>
                  {$tomadorTag}
               </CpfCnpj>
            </IdentificacaoTomador>
            <RazaoSocial>{$this->esc($d['tomador_razao_social'])}</RazaoSocial>
            <Endereco>
               <Endereco>{$this->esc($d['tomador_endereco'])}</Endereco>
               <Numero>{$this->esc($d['tomador_numero'])}</Numero>
               {$complemento}
               <Bairro>{$this->esc($d['tomador_bairro'])}</Bairro>
               <Cep>{$d['tomador_cep']}</Cep>
               <CodigoMunicipio>{$d['tomador_cod_municipio']}</CodigoMunicipio>
            </Endereco>
         </Tomador>
         <OptanteSimplesNacional>{$d['optante_simples_nacional']}</OptanteSimplesNacional>
         <IncentivoFiscal>{$d['incentivo_fiscal']}</IncentivoFiscal>
      </InfDeclaracaoPrestacaoServico>
   </Rps>
</GerarNfseEnvio>
XML;
    }

    /**
     * @param array $dados {
     *   numero_nfse, codigo_municipio, prestador_cpf_cnpj,
     *   prestador_tipo, prestador_im, codigo_cancelamento
     * }
     */
    public function buildCancelarNfseEnvio(array $dados): string
    {
        $d = array_merge(['codigo_cancelamento' => '1'], $dados);

        $prestadorTag = $d['prestador_tipo'] === 'CPF'
            ? "<Cpf>{$d['prestador_cpf_cnpj']}</Cpf>"
            : "<Cnpj>{$d['prestador_cpf_cnpj']}</Cnpj>";

        $ns = self::NAMESPACE_XSD;

        return <<<XML
<CancelarNfseEnvio xmlns="{$ns}">
   <Pedido>
      <InfPedidoCancelamento>
         <IdentificacaoNfse>
            <Numero>{$d['numero_nfse']}</Numero>
            <CpfCnpj>
               {$prestadorTag}
            </CpfCnpj>
            <InscricaoMunicipal>{$d['prestador_im']}</InscricaoMunicipal>
            <CodigoMunicipio>{$d['codigo_municipio']}</CodigoMunicipio>
         </IdentificacaoNfse>
         <CodigoCancelamento>{$d['codigo_cancelamento']}</CodigoCancelamento>
      </InfPedidoCancelamento>
   </Pedido>
</CancelarNfseEnvio>
XML;
    }

    public function buildConsultarNfseRps(array $dados): string
    {
        $prestadorTag = $dados['prestador_tipo'] === 'CPF'
            ? "<Cpf>{$dados['prestador_cpf_cnpj']}</Cpf>"
            : "<Cnpj>{$dados['prestador_cpf_cnpj']}</Cnpj>";

        $ns = self::NAMESPACE_XSD;

        return <<<XML
<ConsultarNfseRpsEnvio xmlns="{$ns}">
   <IdentificacaoRps>
      <Numero>{$dados['numero_rps']}</Numero>
   </IdentificacaoRps>
   <Prestador>
      <CpfCnpj>
         {$prestadorTag}
      </CpfCnpj>
      <InscricaoMunicipal>{$dados['prestador_im']}</InscricaoMunicipal>
   </Prestador>
</ConsultarNfseRpsEnvio>
XML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }

    private function dec(string $v): string
    {
        // Sem vírgula: já está em formato internacional (ex: "1058.82").
        // Com vírgula: formato brasileiro ("1.058,82") — remove pontos de milhar e troca , por .
        if (strpos($v, ',') === false) {
            return number_format((float) $v, 2, '.', '');
        }
        return number_format((float) str_replace(['.', ','], ['', '.'], $v), 2, '.', '');
    }

    private function defaults(array $d): array
    {
        $decFields = [
            'valor_servicos', 'valor_deducoes', 'valor_pis', 'valor_cofins',
            'valor_inss', 'valor_ir', 'valor_csll', 'outras_retencoes',
            'aliquota', 'desconto_incondicionado', 'desconto_condicionado',
        ];
        foreach ($decFields as $f) {
            if (isset($d[$f])) {
                $d[$f] = $this->dec((string) $d[$f]);
            }
        }

        return array_merge([
            'serie_rps'               => '1',
            'tipo_rps'                => 1,
            'status_rps'              => 1,
            'competencia'             => '',
            'valor_deducoes'          => '0',
            'valor_pis'               => '0',
            'valor_cofins'            => '0',
            'valor_inss'              => '0',
            'valor_ir'                => '0',
            'valor_csll'              => '0',
            'outras_retencoes'        => '0',
            'desconto_incondicionado' => '0',
            'desconto_condicionado'   => '0',
            'iss_retido'              => 2,
            'exigibilidade'           => 1,
            'optante_simples_nacional' => 2,
            'incentivo_fiscal'        => 2,
            'numero_nbs'              => '',
            'item_lista_servico'      => '',
            'codigo_cnae'             => '',
            'tomador_complemento'     => '',
        ], $d);
    }
}

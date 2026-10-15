<?php

namespace R2soft\MegasoftNfse\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\MegasoftNfse\MegasoftNfseService;

/**
 * Respostas de ConsultarNfsePorRps com a estrutura observada em Jaraguá/GO (15/10/2026),
 * valores fictícios.
 */
class ConsultarNfsePorRpsTest extends TestCase
{
    private const NOTA = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ConsultarNfseRpsResposta xmlns="http://megasoftarrecadanet.com.br/xsd/nfse_v01.xsd">
  <CompNfse><Nfse versao="1.00"><InfNfse>
    <Numero>3770</Numero>
    <CodigoVerificacao>ABCD-1234</CodigoVerificacao>
    <DataEmissao>2026-10-15T11:39:21.154</DataEmissao>
    <ValoresNfse><BaseCalculo>79.00</BaseCalculo></ValoresNfse>
    <PrestadorServico>
      <Endereco><Endereco>Rua Exemplo</Endereco><Numero>2314</Numero></Endereco>
    </PrestadorServico>
    <DeclaracaoPrestacaoServico><InfDeclaracaoPrestacaoServico>
      <Rps><IdentificacaoRps><Numero>552</Numero></IdentificacaoRps></Rps>
      <Tomador><Endereco><Numero>SN</Numero></Endereco></Tomador>
    </InfDeclaracaoPrestacaoServico></DeclaracaoPrestacaoServico>
  </InfNfse></Nfse></CompNfse>
</ConsultarNfseRpsResposta>
XML;

    private const RPS_INEXISTENTE = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ConsultarNfseRpsResposta xmlns="http://megasoftarrecadanet.com.br/xsd/nfse_v01.xsd">
  <ListaMensagemRetorno><MensagemRetorno>
    <Codigo>L000</Codigo><Mensagem>RPS não encontrada</Mensagem>
  </MensagemRetorno></ListaMensagemRetorno>
</ConsultarNfseRpsResposta>
XML;

    private MegasoftNfseService $svc;

    protected function setUp(): void
    {
        $this->svc = new MegasoftNfseService();
    }

    public function testNotaEncontradaUsaONumeroDaNotaENaoODoEnderecoOuDoRps(): void
    {
        $r = $this->svc->parseRetornoConsulta(self::NOTA);

        $this->assertTrue($r['sucesso']);
        $this->assertTrue($r['encontrada']);
        $this->assertFalse($r['cancelada']);
        $this->assertSame('3770', $r['numero_nfse']);
        $this->assertSame('ABCD-1234', $r['codigo_verificacao']);
        $this->assertSame('2026-10-15T11:39:21.154', $r['data_emissao']);
    }

    public function testRpsInexistenteEhRespostaConclusiva(): void
    {
        $r = $this->svc->parseRetornoConsulta(self::RPS_INEXISTENTE);

        $this->assertTrue($r['sucesso']);
        $this->assertFalse($r['encontrada']);
        $this->assertNull($r['numero_nfse']);
        $this->assertSame('[L000] RPS não encontrada', $r['mensagem']);
    }

    public function testNotaCancelada(): void
    {
        $xml = str_replace('</Nfse>', '</Nfse><NfseCancelamento><Confirmacao/></NfseCancelamento>', self::NOTA);

        $r = $this->svc->parseRetornoConsulta($xml);

        $this->assertTrue($r['encontrada']);
        $this->assertTrue($r['cancelada']);
    }

    public function testOutroErroNaoEhConclusivo(): void
    {
        $xml = str_replace('RPS não encontrada', 'Certificado utilizado na assinatura expirado.', self::RPS_INEXISTENTE);

        $r = $this->svc->parseRetornoConsulta($xml);

        $this->assertFalse($r['sucesso']);
        $this->assertNull($r['encontrada']);
        $this->assertSame('[L000] Certificado utilizado na assinatura expirado.', $r['mensagem']);
    }

    public function testRespostaVaziaNaoEhConclusiva(): void
    {
        $r = $this->svc->parseRetornoConsulta('');

        $this->assertFalse($r['sucesso']);
        $this->assertNull($r['encontrada']);
    }

    public function testConsultarChamaAOperacaoDoWsdl(): void
    {
        // ambiente mock: o SoapClient só responde com nota para 'ConsultarNfsePorRps'
        $r = $this->svc->consultar(
            ['wsdl_url' => 'https://exemplo.invalid/ws?wsdl', 'ambiente' => 'mock'],
            ['numero_rps' => '1', 'prestador_cpf_cnpj' => '00000000000191', 'prestador_tipo' => 'CNPJ', 'prestador_im' => '1']
        );

        $this->assertTrue($r['encontrada']);
        $this->assertSame('99999', $r['numero_nfse']);
    }
}

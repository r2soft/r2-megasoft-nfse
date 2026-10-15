<?php

namespace R2soft\MegasoftNfse\Tests;

use PHPUnit\Framework\TestCase;
use R2soft\MegasoftNfse\MegasoftXmlBuilder;

/**
 * Jaraguá/GO recusa complemento do tomador com mais de 30 caracteres (15/10/2026).
 */
class ComplementoTomadorTest extends TestCase
{
    private function xml(?string $complemento): string
    {
        return (new MegasoftXmlBuilder())->buildGerarNfseEnvio([
            'numero_rps'            => '1',
            'data_emissao'          => '2026-10-15',
            'valor_servicos'        => '100.00',
            'discriminacao'         => 'Serviço',
            'codigo_tributacao'     => '1',
            'prestador_cpf_cnpj'    => '11222333000181',
            'prestador_tipo'        => 'CNPJ',
            'prestador_im'          => '1',
            'tomador_cpf_cnpj'      => '11222333000181',
            'tomador_tipo'          => 'CNPJ',
            'tomador_razao_social'  => 'Cliente',
            'tomador_endereco'      => 'Rua A',
            'tomador_numero'        => '1',
            'tomador_complemento'   => $complemento,
            'tomador_bairro'        => 'Centro',
            'tomador_cep'           => '76330000',
            'tomador_cod_municipio' => '5211800',
            'codigo_municipio'      => '5211800',
            'aliquota'              => '2',
        ], 'pfx1');
    }

    private function complemento(string $xml): ?string
    {
        return preg_match('#<Complemento>(.*?)</Complemento>#su', $xml, $m)
            ? html_entity_decode($m[1], ENT_XML1, 'UTF-8')
            : null;
    }

    public function testCortaEm30Caracteres(): void
    {
        $c = $this->complemento($this->xml('Andar:7;Sala:704/705;Ed.:Aspen Park Center'));

        $this->assertSame('Andar:7;Sala:704/705;Ed.:Aspen', $c);
    }

    public function testContaCaracteresNaoBytes(): void
    {
        $c = $this->complemento($this->xml(str_repeat('Á', 35)));

        $this->assertSame(str_repeat('Á', 30), $c); // 60 bytes, UTF-8 íntegro
    }

    public function testRemoveEspacoQueSobraNoCorte(): void
    {
        $c = $this->complemento($this->xml('Sala 502 do Edifício Tropical Center'));

        $this->assertSame('Sala 502 do Edifício Tropical', $c);
    }

    public function testCurtoFicaIgual(): void
    {
        $this->assertSame('Sala 214 & Ed. Prospere', $this->complemento($this->xml(' Sala 214 & Ed. Prospere ')));
    }

    public function testVazioNaoGeraTag(): void
    {
        $this->assertNull($this->complemento($this->xml('   ')));
        $this->assertNull($this->complemento($this->xml(null)));
    }
}

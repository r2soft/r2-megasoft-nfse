<?php

namespace R2soft\MegasoftNfse;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Assina XML NFSe com certificado ICP-Brasil A1 (.pfx).
 *
 * Algoritmos padrão Megasoft ABRASF:
 *   - Canonicalização: Exclusive C14N
 *   - Assinatura:      RSA-SHA1
 *   - Digest:          SHA1
 *   - Transforms:      enveloped-signature + exc-c14n#
 */
class MegasoftXmlSigner
{
    /**
     * Assina o XML e retorna o XML com <ds:Signature> inserida.
     *
     * @param string $xml          XML sem assinatura gerado por MegasoftXmlBuilder
     * @param string $pfxConteudo  Conteúdo binário do arquivo .pfx
     * @param string $pfxSenha     Senha do certificado
     * @param string $rpsId        Id do elemento <Rps> interno (ex: "pfxd12b7a9b-...")
     * @throws MegasoftNfseException
     */
    public function sign(string $xml, string $pfxConteudo, string $pfxSenha, string $rpsId): string
    {
        if ($pfxConteudo === '') {
            throw new MegasoftNfseException('Certificado digital não configurado.');
        }

        if (!openssl_pkcs12_read($pfxConteudo, $certInfo, $pfxSenha)) {
            throw new MegasoftNfseException('Senha do certificado inválida ou arquivo .pfx corrompido.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        if (!$doc->loadXML($xml)) {
            throw new MegasoftNfseException('XML inválido, não foi possível fazer o parse.');
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ns', MegasoftXmlBuilder::NAMESPACE_XSD);
        $rpsNodes = $xpath->query("//*[@Id='{$rpsId}']");

        if ($rpsNodes->length === 0) {
            throw new MegasoftNfseException("Elemento com Id='{$rpsId}' não encontrado no XML.");
        }

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        $dsig->addReference(
            $rpsNodes->item(0),
            XMLSecurityDSig::SHA1,
            [
                'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                XMLSecurityDSig::EXC_C14N,
            ],
            ['id_name' => 'Id', 'overwrite' => false]
        );

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
        $objKey->loadKey($certInfo['pkey'], false);

        $dsig->sign($objKey);
        $dsig->add509Cert($certInfo['cert'], true, false);

        $rpsExterno = $xpath->query('//ns:GerarNfseEnvio/ns:Rps')->item(0)
            ?? $doc->getElementsByTagName('Rps')->item(0);

        if ($rpsExterno === null) {
            throw new MegasoftNfseException('Elemento <Rps> externo não encontrado para inserção da assinatura.');
        }

        $dsig->appendSignature($rpsExterno);

        return $doc->saveXML($doc->documentElement);
    }

    public static function gerarRpsId(): string
    {
        $uuid = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffffffffffff)
        );
        return 'pfx' . $uuid;
    }
}

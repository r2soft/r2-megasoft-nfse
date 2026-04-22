<?php

namespace R2soft\MegasoftNfse;

/**
 * Wrapper SOAP para o webservice Megasoft NFSe.
 * Operações: GerarNfse, CancelarNfse, ConsultarNfseRps
 */
class MegasoftSoapClient
{
    private string  $wsdlUrl;
    private string  $ambiente;
    private int     $timeout;
    private ?string $certPath = null;
    private ?string $certSenha;
    private string  $wsdlCacheDir;
    private ?string $certTempPath = null;

    /**
     * @param string|null $certConteudo Conteúdo binário do .pfx (materializado em tmpfile para uso pelo curl).
     * @param string|null $wsdlCacheDir Diretório para cache do WSDL. Default: sys_get_temp_dir()
     */
    public function __construct(
        string  $wsdlUrl,
        string  $ambiente = 'prod',
        int     $timeout = 30,
        ?string $certConteudo = null,
        ?string $certSenha = null,
        ?string $wsdlCacheDir = null
    ) {
        $this->wsdlUrl      = trim($wsdlUrl);
        $this->ambiente     = $ambiente;
        $this->timeout      = $timeout;
        $this->certSenha    = $certSenha;
        $this->wsdlCacheDir = rtrim($wsdlCacheDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        if ($certConteudo !== null && $certConteudo !== '') {
            $tmp = tempnam(sys_get_temp_dir(), 'nfse_cert_');
            file_put_contents($tmp, $certConteudo);
            $this->certTempPath = $tmp;
            $this->certPath     = $tmp;
        }
    }

    public function __destruct()
    {
        if ($this->certTempPath && file_exists($this->certTempPath)) {
            @unlink($this->certTempPath);
        }
    }

    /**
     * Envia XML assinado para a operação SOAP e retorna o XML de resposta.
     *
     * @param string $operacao  GerarNfse | CancelarNfse | ConsultarNfseRps
     * @param string $xmlEnvio  XML completo com assinatura digital
     * @throws MegasoftNfseException
     */
    public function call(string $operacao, string $xmlEnvio): string
    {
        if ($this->ambiente === 'mock') {
            return $this->mockResponse($operacao);
        }

        try {
            $verificarSsl = ($this->ambiente === 'prod');
            $this->baixarWsdlSeNecessario($verificarSsl);

            $endpointSoap = preg_replace('/\?wsdl$/i', '', $this->wsdlUrl);
            return $this->callViaCurl($endpointSoap, $operacao, $xmlEnvio, $verificarSsl);

        } catch (\SoapFault $e) {
            throw new MegasoftNfseException("Erro SOAP [{$e->faultcode}]: {$e->faultstring}", (int) $e->getCode());
        } catch (\Exception $e) {
            throw new MegasoftNfseException($e->getMessage(), (int) $e->getCode());
        }
    }

    private function baixarWsdlSeNecessario(bool $verificarSsl): void
    {
        $wsdlCache = $this->wsdlCacheDir . DIRECTORY_SEPARATOR . 'nfse_megasoft.wsdl';

        if (file_exists($wsdlCache)) {
            return;
        }

        $opts        = $this->baseCurlOpts($verificarSsl);
        $ch          = curl_init($this->wsdlUrl);
        curl_setopt_array($ch, $opts);
        $wsdlContent = curl_exec($ch);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($wsdlContent === false) {
            throw new MegasoftNfseException("Não foi possível baixar o WSDL ({$this->wsdlUrl}): {$curlError}");
        }

        // Fallback: tenta inserir /api/ na URL (Megasoft moderno)
        if (!$this->isXml($wsdlContent)) {
            $urlApi = preg_replace('#(https?://[^/]+)/#', '$1/api/', $this->wsdlUrl, 1);
            if ($urlApi !== $this->wsdlUrl) {
                $ch2 = curl_init($urlApi);
                curl_setopt_array($ch2, $opts + [CURLOPT_URL => $urlApi]);
                $wsdlContent2 = curl_exec($ch2);
                curl_close($ch2);
                if ($wsdlContent2 !== false && $this->isXml($wsdlContent2)) {
                    $wsdlContent = $wsdlContent2;
                }
            }
        }

        if (!$this->isXml($wsdlContent)) {
            throw new MegasoftNfseException(
                "A URL WSDL não retornou XML válido ({$this->wsdlUrl}). Resposta: " . substr($wsdlContent, 0, 200)
            );
        }

        file_put_contents($wsdlCache, $wsdlContent);
    }

    private function callViaCurl(string $endpoint, string $operacao, string $xmlEnvio, bool $verificarSsl): string
    {
        $cabecalho = $this->buildCabecalho();
        $ns        = 'http://ws.megasoftarrecadanet.com.br';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="' . $ns . '">'
            . '<soap:Body>'
            . '<ws:' . $operacao . 'Request>'
            . '<nfseCabecMsg>' . htmlspecialchars($cabecalho, ENT_XML1, 'UTF-8') . '</nfseCabecMsg>'
            . '<nfseDadosMsg>' . htmlspecialchars($xmlEnvio,  ENT_XML1, 'UTF-8') . '</nfseDadosMsg>'
            . '</ws:' . $operacao . 'Request>'
            . '</soap:Body>'
            . '</soap:Envelope>';

        $opts = $this->baseCurlOpts($verificarSsl);
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $envelope;
        $opts[CURLOPT_HTTPHEADER] = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $ns . '/' . $operacao . '"',
        ];

        $ch       = curl_init($endpoint);
        curl_setopt_array($ch, $opts);
        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new MegasoftNfseException("Erro curl ao chamar SOAP: {$curlError}");
        }

        if (preg_match('/<outputXML>(.*?)<\/outputXML>/s', $response, $m)) {
            return html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        return $response;
    }

    private function baseCurlOpts(bool $verificarSsl): array
    {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $verificarSsl,
            CURLOPT_SSL_VERIFYHOST => $verificarSsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($this->certPath && file_exists($this->certPath)) {
            $opts[CURLOPT_SSLCERT]     = $this->certPath;
            $opts[CURLOPT_SSLCERTTYPE] = 'P12';
            if ($this->certSenha) {
                $opts[CURLOPT_SSLCERTPASSWD] = $this->certSenha;
            }
        }

        return $opts;
    }

    private function buildCabecalho(): string
    {
        return '<cabecalho xmlns="http://megasoftarrecadanet.com.br/xsd/nfse_v01.xsd" versao="2.04">'
            . '<versaoDados>2.04</versaoDados>'
            . '</cabecalho>';
    }

    private function isXml(string $content): bool
    {
        return stripos($content, '<?xml') !== false
            || stripos($content, '<definitions') !== false
            || stripos($content, '<wsdl:') !== false;
    }

    private function mockResponse(string $operacao): string
    {
        if ($operacao === 'GerarNfse') {
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<GerarNfseResposta xmlns="http://megasoftarrecadanet.com.br/xsd/nfse_v01.xsd">'
                . '<ListaNfse><CompNfse><Nfse><InfNfse>'
                . '<Numero>99999</Numero>'
                . '<CodigoVerificacao>MOCK00</CodigoVerificacao>'
                . '<DataEmissao>' . date('Y-m-d') . 'T' . date('H:i:s') . '</DataEmissao>'
                . '</InfNfse></Nfse></CompNfse></ListaNfse>'
                . '</GerarNfseResposta>';
        }
        if ($operacao === 'CancelarNfse') {
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<CancelarNfseResposta xmlns="http://megasoftarrecadanet.com.br/xsd/nfse_v01.xsd">'
                . '<RetCancelamento><NfseCancelamento><Confirmacao>'
                . '<DataHora>' . date('Y-m-d\TH:i:s') . '</DataHora>'
                . '</Confirmacao></NfseCancelamento></RetCancelamento>'
                . '</CancelarNfseResposta>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><MockResposta/>';
    }
}

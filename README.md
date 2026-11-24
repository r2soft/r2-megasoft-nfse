# r2soft/nfse-megasoft

Emissão, cancelamento e consulta de NFS-e em municípios atendidos pela Megasoft (ABRASF v2.04, via SOAP). O XML é assinado com o certificado A1 da empresa.

```bash
composer require r2soft/nfse-megasoft
```

```php
$service = new \R2soft\MegasoftNfse\MegasoftNfseService();
$config  = ['wsdl_url' => $wsdl, 'certificado_conteudo' => $pfxBinario, 'certificado_senha' => $senha, 'ambiente' => 'prod'];

$r = $service->emitir($config, $dadosRps);   // sucesso, numero_nfse, codigo_verificacao, xml_envio, xml_retorno, mensagem
$r = $service->cancelar($config, $dados);    // sucesso, xml_retorno, mensagem
$r = $service->consultar($config, $dados);   // sucesso, encontrada, cancelada, numero_nfse, codigo_verificacao, ...
```

- Os campos de `$dadosRps` estão em `MegasoftXmlBuilder::buildGerarNfseEnvio`.
- Se a emissão ficar sem resposta, use `consultar()` com `numero_rps`, `prestador_cpf_cnpj`, `prestador_tipo` e `prestador_im` para saber se o RPS virou nota antes de gravar erro.

Testes: `composer install && vendor/bin/phpunit tests`.

Licença MIT.

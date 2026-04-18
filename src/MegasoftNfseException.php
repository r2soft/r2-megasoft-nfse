<?php

namespace R2soft\MegasoftNfse;

class MegasoftNfseException extends \RuntimeException
{
    private ?string $xmlResposta;

    public function __construct(string $message, int $code = 0, ?string $xmlResposta = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->xmlResposta = $xmlResposta;
    }

    public function getXmlResposta(): ?string
    {
        return $this->xmlResposta;
    }
}

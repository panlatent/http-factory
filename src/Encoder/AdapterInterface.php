<?php

namespace Aurora\Http\Message\Encoder;

use Aurora\Http\Message\Encoder;
use Psr\Http\Message\ResponseInterface;

interface AdapterInterface
{
    /**
     * Returns a response decode stream.
     *
     * @param Encoder           $encoder
     * @param ResponseInterface $response
     * @return Stream
     */
    public function createStream(Encoder $encoder, ResponseInterface $response): Stream;
}
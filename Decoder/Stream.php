<?php

namespace Aurora\Http\Codec\Decoder;

use Aurora\Context\ContextSensitiveInterface;
use Aurora\Http\Codec\Stream\ResolveStreamInterface;
use Aurora\Http\Codec\Stream\WritableException;
use BadMethodCallException;

class Stream implements ResolveStreamInterface, ContextSensitiveInterface
{
    const MSG_LINE_WAITING = 1;
    const MSG_LINE_DOING = 2;
    const MSG_HEAD_WAITING = 4;
    const MSG_HEAD_DOING = 5;
    const MSG_HEAD_DONE = 6;
    const MSG_BODY_WAITING = 7;
    const MSG_BODY_DOING = 8;
    const MSG_BODY_DONE = 9;

    /**
     * @var int request message handle status.
     */
    protected $messageStatus;
    /**
     * @var resource data size max limit is 2M (see php://memory).
     */
    protected $lineBuffer;
    /**
     * @var resource data size max limit is 2M (see php://memory).
     */
    protected $headerBuffer;
    /**
     * @var resource
     */
    protected $bodyBuffer;
    /**
     * @var StreamContext
     */
    protected $context;
    /**
     * @var int
     */
    protected $bodyLength;

    /**
     * RequestStream constructor.
     */
    public function __construct()
    {
        $this->context = new StreamContext();
        $this->messageStatus = static::MSG_LINE_WAITING;
    }

    /**
     * Write raw request message.
     *
     * @param string $content
     * @return bool|int the number of bytes written, or <b>FALSE</b> on error.
     */
    public function write($content)
    {
        $this->prepare();

        if ($this->messageStatus == static::MSG_LINE_DOING) {
            /*
             * Find request line.
             */
            if (false === ($pos = strpos($content, static::HTTP_MESSAGE_LINE_ENDING))) {
                $writtenLength = fwrite($this->lineBuffer, $content);

                return $writtenLength;
            } else {
                $writtenLength = fwrite($this->lineBuffer, substr($content, 0, $pos));
                $this->messageStatus = static::MSG_HEAD_WAITING;

                return $writtenLength + strlen(static::HTTP_MESSAGE_LINE_ENDING);
            }
        } elseif ($this->messageStatus == static::MSG_HEAD_DOING) {
            /*
             * Find request header end.
             */
            if (false === ($pos = strpos($content, static::HTTP_MESSAGE_HEADER_ENDING))) {
                $writtenLength = fwrite($this->headerBuffer, $content);

                return $writtenLength;
            } else {
                $writtenLength = fwrite($this->headerBuffer, substr($content, 0, $pos));
                $this->messageStatus = static::MSG_HEAD_DOING;

                $this->context->headerReady();

                return $writtenLength + strlen(static::HTTP_MESSAGE_HEADER_ENDING);
            }
        } elseif ($this->messageStatus == static::MSG_BODY_DOING) {
            /*
             * Write request body.
             */
            $writtenLength = fwrite($this->bodyBuffer, $content);
            $this->bodyLength += $writtenLength;
            $headers = $this->getStandardHeaders();
            if (isset($headers['Content-Length'])) {
                if ($this->bodyLength >= $headers['Content-Length']) {
                    $this->messageStatus = static::MSG_BODY_DONE;
                    $this->context->bodyReady();
                }
            }

            return $writtenLength;
        }

        throw new WritableException("Cannot write to stream: message status is marked #{$this->messageStatus}");
    }

    /**
     * @return int
     */
    public function getMessageStatus()
    {
        return $this->messageStatus;
    }

    /**
     * @var string
     */
    private $method;

    /**
     * @return string
     */
    public function getMethod()
    {
        if ($this->method === null) {
            fseek($this->lineBuffer, 0);
            $part = fread($this->lineBuffer, $this->context->methodMaxLength + 1);
            if (false === ($pos = strpos($part, ' '))) {
                throw new MessageHeaderException('Unrecognized http request method');
            }
            $this->method = substr($part, 0, $pos);
        }

        return $this->method;
    }

    /**
     * @var string
     */
    private $version;

    /**
     * Returns a string of HTTP version.
     *
     * The value with "HTTP/" prefix, e.g. HTTP/1.1
     *
     * @return string
     */
    public function getVersion(): string
    {
        if ($this->version === null) {
            fseek($this->lineBuffer, -($this->context->versionMaxLength + 1), SEEK_END);
            $part = fread($this->lineBuffer, $this->context->versionMaxLength + 1);
            if (false === ($rightPos = strrpos($part, ' '))) {
                throw new MessageHeaderException('Unrecognized http request version');
            }
            $this->version = substr($part, $rightPos + 1);
        }

        return $this->version;
    }

    /**
     * @var string
     */
    private $uri;

    /**
     * Returns a sting of request line uri part
     *
     * he reason for not using explode() is URI part may be too length. Another,
     * the method allow URI part contains space characters.
     *
     * @return string
     */
    public function getUri(): string
    {
        if ($this->uri === null) {
            $pos = strlen($this->getMethod());
            fseek($this->lineBuffer, $pos + 1);
            $uri = '';
            for ($i = $pos + 1; ! feof($this->lineBuffer); $i += 1024) {
                $uri .= fread($this->lineBuffer, 1024);
            }
            $this->uri = substr($uri, 0, -(strlen($this->getVersion()) + 1));
        }

        return $this->uri;
    }

    private $headers;

    /**
     * Returns an array of contains all request headers.
     *
     * This method can to handle multiple line header, but it doesn't parse
     * the header value to array. The return value allow same name headers.
     * e.g.
     * [
     *     ['Content-Type', 'application/json'],
     *     ['Accept', 'application/json;text/html'],
     *     ['Accept', 'application/xml'],
     * ]
     *
     * @return array
     */
    public function getHeaders()
    {
        if ($this->headers !== null) {
            return $this->headers;
        }
        $headers = [];
        for (rewind($this->headerBuffer); ! feof($this->headerBuffer);) {
            $line = stream_get_line(
                $this->headerBuffer,
                $this->context->headerLineMaxLength,
                static::HTTP_MESSAGE_LINE_ENDING);
            $line = (array)explode(':', $line, 2);
            if (count($line) == 2) {
                $headers[] = [$line[0], ltrim($line[1], " \t")];
            } elseif (! empty($line[0]) && ($line[0][0] == " " || $line[0][0] == "\t")) {
                /*
                 * Multi lines header.
                 */
                $header = array_pop($headers);
                $header[1] .= ltrim($line[0]);
                $headers[] = $header;
            }
        }

        return $this->headers = $headers;
    }

    /**
     * @var array
     */
    private $standardHeaders;

    /**
     * Returns an array of contains all request headers.
     *
     * Each different name header has only one.
     *
     * @see Stream::getHeaders()
     *
     * @return array
     */
    public function getStandardHeaders()
    {
        if ($this->standardHeaders === null) {
            $standardHeaders = [];
            foreach ($this->getHeaders() as list($name, $value)) {
                if (! isset($standardHeaders[$name])) {
                    $standardHeaders[$name] = $value;
                } else {
                    $standardHeaders[$name] .= ', ' . $value;
                }
            }
            $this->standardHeaders = $standardHeaders;
        }

        return $this->standardHeaders;
    }

    /**
     * @return bool|string
     */
    public function getBodyContent()
    {
        if ($this->bodyBuffer === null) {
            return '';
        }

        return stream_get_contents($this->bodyBuffer, -1, 0);
    }

    /**
     * @return resource
     */
    public function getBodyStream()
    {
        if ($this->bodyBuffer === null) {
            throw new MessageBodyException('Body stream does not exist');
        }

        return $this->bodyBuffer;
    }

    public function close()
    {
        // TODO: Implement close() method.
    }

    public function detach()
    {
        // TODO: Implement detach() method.
    }

    public function getSize()
    {
        // TODO: Implement getSize() method.
    }

    public function tell()
    {
        // TODO: Implement tell() method.
    }

    public function eof()
    {
        // TODO: Implement eof() method.
    }

    public function isSeekable()
    {
        // TODO: Implement isSeekable() method.
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        // TODO: Implement seek() method.
    }

    public function rewind()
    {
        // TODO: Implement rewind() method.
    }

    public function read($length)
    {
        // TODO: Implement read() method.
    }

    public function getContents()
    {
        // TODO: Implement getContents() method.
    }

    public function getMetadata($key = null)
    {
        // TODO: Implement getMetadata() method.
    }

    /**
     * @return StreamContext
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @return bool
     */
    public function isReadable()
    {
        if ($this->messageStatus < static::MSG_HEAD_DONE) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function isWritable()
    {
        if ($this->messageStatus < static::MSG_LINE_DOING) {
            return true;
        }
        if (in_array($this->getMethod(), $this->context->withoutBodyMethods)) {
            return $this->messageStatus != static::MSG_HEAD_DONE;
        }

        return $this->messageStatus != static::MSG_BODY_DONE;
    }

    /**
     * @return bool
     */
    public function isWithBody()
    {
        if ($this->messageStatus < static::MSG_LINE_DOING) {
            throw new BadMethodCallException('Request line unknown');
        }

        return in_array($this->getMethod(), $this->context->withBodyMethods);
    }

    /**
     * @return bool
     */
    public function isWithoutBody()
    {
        if ($this->messageStatus < static::MSG_LINE_DOING) {
            throw new BadMethodCallException('Request line unknown');
        }

        return in_array($this->getMethod(), $this->context->withoutBodyMethods);
    }

    public function __toString()
    {
        return '';
    }

    /**
     * Prepare message status.
     */
    protected function prepare()
    {
        if ($this->messageStatus == static::MSG_HEAD_DONE) {
            if (in_array(strtoupper($this->getMethod()), $this->context->withBodyMethods)) {
                $this->messageStatus = static::MSG_BODY_WAITING;
            }
        }
        if ($this->messageStatus == static::MSG_LINE_WAITING) {
            $this->lineBuffer = fopen('php://memory', 'r+');
            $this->messageStatus = static::MSG_LINE_DOING;
        } elseif ($this->messageStatus == static::MSG_HEAD_WAITING) {
            $this->headerBuffer = fopen('php://memory', 'r+');
            $this->messageStatus = static::MSG_HEAD_DOING;
        } elseif ($this->messageStatus == static::MSG_BODY_WAITING) {
            $this->bodyBuffer = fopen('php://temp', 'r+');
            $this->bodyLength = 0;
            $this->messageStatus = static::MSG_BODY_DOING;
        }
    }
}
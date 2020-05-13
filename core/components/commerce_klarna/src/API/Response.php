<?php

namespace modmore\Commerce_Klarna\API;

use Psr\Http\Message\ResponseInterface;

class Response {
    /**
     * @var bool
     */
    private $success;
    /**
     * @var array
     */
    private $data;

    private $errors = [];

    public function __construct(bool $success, array $data = [])
    {
        $this->success = $success;
        $this->data = $data;
    }

    public function addError(string $code, string $message): void
    {
        $this->errors[] = ['code' => $code, 'message' => $message];
    }

    public static function from(ResponseInterface $response): self
    {
        $body = $response->getBody()->getContents();
        $statusCode = $response->getStatusCode();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $inst = new static(false);
            $inst->addError('http_' . $statusCode, $body);
            return $inst;
        }
        $success = $statusCode === 200;

        $inst = new static(
            $success,
            $data
        );

        if (!$success) {
            $errCode = $data['error_code'];
            $errMessages = array_key_exists('error_message', $data) ? [$data['error_message']] : $data['error_messages'];
            foreach ($errMessages as $msg) {
                $inst->addError($errCode, $msg);
            }
        }

        return $inst;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }
}
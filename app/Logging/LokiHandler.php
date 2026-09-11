<?php

namespace App\Logging;

use GuzzleHttp\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class LokiHandler extends AbstractProcessingHandler
{
    private readonly Client $client;
    private ?string $password = null;

    public function __construct(private readonly array $config)
    {
        parent::__construct(Level::fromName($config['level'] ?? 'debug'));
        $this->client = new Client;
        $this->setFormatter(new ObservabilityJsonFormatter);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $this->password ??= trim((string) file_get_contents($this->config['password_file']));
            if ($this->password === '') return;

            $this->client->post($this->config['url'], [
                'auth' => [$this->config['username'], $this->password],
                'connect_timeout' => $this->config['timeout'],
                'timeout' => $this->config['timeout'],
                'http_errors' => false,
                'json' => ['streams' => [[
                    'stream' => $this->config['labels'],
                    'values' => [[$record->datetime->format('Uu').'000', trim($record->formatted)]],
                ]]],
            ]);
        } catch (Throwable) {
            // Logging must never break an application request.
        }
    }
}

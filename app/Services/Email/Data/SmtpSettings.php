<?php

namespace App\Services\Email\Data;

use InvalidArgumentException;

/**
 * A business's own SMTP account. Built from validated input only; the password is never printed or dumped.
 */
final readonly class SmtpSettings
{
    public const ENCRYPTION_TLS = 'tls';

    public const ENCRYPTION_SSL = 'ssl';

    public const ENCRYPTION_NONE = 'none';

    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public string $encryption,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException with a message that is safe to show the business
     */
    public static function fromInput(array $input): self
    {
        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $encryption = strtolower(trim((string) ($input['encryption'] ?? self::ENCRYPTION_TLS)));
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            throw new InvalidArgumentException('Enter the server name, for example smtp.example.com.');
        }

        if ($port === false) {
            throw new InvalidArgumentException('Enter a port number between 1 and 65535. Common ports are 587 (STARTTLS), 465 (SSL) and 25.');
        }

        if (! in_array($encryption, [self::ENCRYPTION_TLS, self::ENCRYPTION_SSL, self::ENCRYPTION_NONE], true)) {
            throw new InvalidArgumentException('Choose TLS, SSL or no encryption.');
        }

        if ($username === '' || mb_strlen($username) > 254) {
            throw new InvalidArgumentException('Enter the username your email provider gave you.');
        }

        if ($password === '' || mb_strlen($password) > 512) {
            throw new InvalidArgumentException('Enter the password or app password for this account.');
        }

        return new self($host, $port, $username, $password, $encryption);
    }

    /**
     * What is stored, encrypted, on the connection.
     *
     * @return array{host: string, port: int, username: string, password: string, encryption: string}
     */
    public function toCredentials(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'password' => $this->password, 'encryption' => $this->encryption];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function fromCredentials(array $credentials): self
    {
        return new self((string) $credentials['host'], (int) $credentials['port'], (string) $credentials['username'], (string) $credentials['password'], (string) $credentials['encryption']);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => (string) $this->port, 'encryption' => $this->encryption, 'password' => '[redacted]'];
    }
}

<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Domain\LtvCalculator;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use CarMoneyLab\Http\ApplicationController;
use CarMoneyLab\Repository\ApplicationRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Учебный двойник PDO для тестов контроллера: INSERT в applications падает с заданным кодом MySQL.
 */
final class UniqueViolationPdo extends PDO
{
    /** @param array{0:int,1:string} $mysqlError код MySQL и сообщение */
    public function __construct(private readonly array $mysqlError)
    {
        // Родительский конструктор не вызываем: соединение не нужно, все методы переопределены.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'INSERT INTO applications')) {
            $exception = new PDOException('duplicate entry', 0);
            $exception->errorInfo = ['23000', $this->mysqlError[0], $this->mysqlError[1]];

            throw $exception;
        }

        // Соединения нет (конструктор не вызывался) — другие запросы двойнику не нужны.
        throw new PDOException('not supported by test double');
    }
}

final class ApplicationControllerTest extends TestCase
{
    private static function controller(): ApplicationController
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $age = new VehicleAge((int) date('Y'));

        $assessment = new AssessmentService(
            new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
            new LtvCalculator(),
            new DecisionEngine($rules['ltv']),
            $age,
        );
        $repository = new ApplicationRepository(new UniqueViolationPdo([1062, 'Duplicate entry']));

        return new ApplicationController($assessment, $repository);
    }

    /** @return array<string,mixed> */
    private static function validPayload(): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 96000,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
            'applicant_ref' => 'CL-TST-UNIT',
        ];
    }

    private static function request(array $payload): \Psr\Http\Message\ServerRequestInterface
    {
        return (new \Slim\Psr7\Factory\RequestFactory())
            ->createRequest('POST', '/api/applications')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);
    }

    public function testCreateReturns409WithApplicantRefErrorOnDuplicateActiveApplication(): void
    {
        $response = self::controller()->create(
            self::request(self::validPayload()),
            new \Slim\Psr7\Response(),
        );

        $body = (array) json_decode((string) $response->getBody(), true);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            (new DuplicateActiveApplicationException('CL-ANON'))->getMessage(),
            $body['errors']['applicant_ref'] ?? null,
        );
    }
}
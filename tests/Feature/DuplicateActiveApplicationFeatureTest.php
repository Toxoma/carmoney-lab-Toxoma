<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\AppFactory;
use CarMoneyLab\Database;
use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * Фича «запрет второй активной заявки клиента» (AC-1..AC-7 из спецификации).
 * Требует поднятую MySQL 8 со схемой db/schema.sql (или применённой миграцией).
 * При недоступной базе тесты пропускаются (markTestSkipped).
 */
final class DuplicateActiveApplicationFeatureTest extends TestCase
{
    private const REF_PREFIX = 'CL-TST-';

    private PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connect();
            $this->pdo->query('SELECT is_active FROM applications LIMIT 1');
        } catch (PDOException $exception) {
            self::markTestSkipped(
                'MySQL недоступна или не применена миграция 2026-10-22: ' . $exception->getMessage(),
            );
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM applications WHERE applicant_ref LIKE :ref');
        $statement->execute([':ref' => self::REF_PREFIX . '%']);
    }

    private static function uniqRef(): string
    {
        return self::REF_PREFIX . uniqid();
    }

    /** @return array<string,mixed> */
    private static function validPayload(string $applicantRef): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 96000,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
            'applicant_ref' => $applicantRef,
        ];
    }

    private static function post(PDO $pdo, array $payload): ResponseInterface
    {
        /** @var App $app */
        $app = AppFactory::create($pdo);

        $request = (new \Slim\Psr7\Factory\RequestFactory())
            ->createRequest('POST', '/api/applications')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);

        return $app->handle($request);
    }

    private static function body(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function activeCount(string $applicantRef): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM applications WHERE applicant_ref = :ref AND status IN ('new', 'decided')",
        );
        $statement->execute([':ref' => $applicantRef]);

        return (int) $statement->fetchColumn();
    }

    private function totalCount(string $applicantRef): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM applications WHERE applicant_ref = :ref',
        );
        $statement->execute([':ref' => $applicantRef]);

        return (int) $statement->fetchColumn();
    }

    // Считаем vehicles/decisions только по заявкам конкретного клиента: глобальные COUNT(*)
    // по таблицам ловят чужие параллельные записи и дают ложный FAIL.

    private function vehicleCount(string $applicantRef): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM vehicles v
             JOIN applications a ON a.id = v.application_id
             WHERE a.applicant_ref = :ref',
        );
        $statement->execute([':ref' => $applicantRef]);

        return (int) $statement->fetchColumn();
    }

    private function decisionCount(string $applicantRef): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM decisions d
             JOIN applications a ON a.id = d.application_id
             WHERE a.applicant_ref = :ref',
        );
        $statement->execute([':ref' => $applicantRef]);

        return (int) $statement->fetchColumn();
    }

    private function insertApplication(PDO $pdo, string $applicantRef): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
             VALUES (:ref, 450000, 24, :status)'
        );
        $statement->execute([':ref' => $applicantRef, ':status' => 'new']);
    }

    // AC-1: первая заявка нового клиента — 201 и одна строка в БД.

    public function testFirstApplicationOfNewClientReturns201AndSingleRow(): void
    {
        $ref = self::uniqRef();

        $response = self::post($this->pdo, self::validPayload($ref));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->activeCount($ref));
    }

    // AC-2 + AC-5: повторная активная заявка — 409, JSON c errors.applicant_ref, новых записей нет.

    public function testSecondActiveApplicationReturns409AndAddsNoRows(): void
    {
        $ref = self::uniqRef();
        self::post($this->pdo, self::validPayload($ref));

        // Базовые счётчики снимаем по этому же клиенту, а не по всей таблице.
        $rowsBefore = $this->totalCount($ref);
        $vehiclesBefore = $this->vehicleCount($ref);
        $decisionsBefore = $this->decisionCount($ref);

        $response = self::post($this->pdo, self::validPayload($ref));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type'),
        );
        // Текст сообщения задаётся в DuplicateActiveApplicationException — здесь не дублируем.
        self::assertSame(
            (new DuplicateActiveApplicationException($ref))->getMessage(),
            self::body($response)['errors']['applicant_ref'] ?? null,
        );
        self::assertSame($rowsBefore, $this->totalCount($ref));
        self::assertSame($vehiclesBefore, $this->vehicleCount($ref));
        self::assertSame($decisionsBefore, $this->decisionCount($ref));
    }

    // AC-3: после перевода в cancelled новая заявка того же клиента проходит.

    public function testCancelledClientCanCreateNewApplication(): void
    {
        $ref = self::uniqRef();
        self::post($this->pdo, self::validPayload($ref));

        $statement = $this->pdo->prepare(
            "UPDATE applications SET status = 'cancelled' WHERE applicant_ref = :ref",
        );
        $statement->execute([':ref' => $ref]);

        $response = self::post($this->pdo, self::validPayload($ref));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->activeCount($ref));
        self::assertSame(2, $this->totalCount($ref));
    }

    // AC-6: заявка другого клиента не блокируется чужой активной заявкой.

    public function testOtherClientApplicationIsNotBlocked(): void
    {
        self::post($this->pdo, self::validPayload(self::uniqRef()));
        $otherRef = self::uniqRef();

        $response = self::post($this->pdo, self::validPayload($otherRef));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->activeCount($otherRef));
    }

    // AC-7: невалидные данные при активной заявке — 422 (валидация раньше повтора), записей не прибавилось.

    public function testInvalidDataWithActiveApplicationReturns422AndAddsNoRows(): void
    {
        $ref = self::uniqRef();
        self::post($this->pdo, self::validPayload($ref));

        $rowsBefore = $this->totalCount($ref);
        $invalid = self::validPayload($ref);
        $invalid['year'] = (int) date('Y') + 1;

        $response = self::post($this->pdo, $invalid);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($rowsBefore, $this->totalCount($ref));
    }

    // AC-4, фаза удержания: INSERT без COMMIT держит лок, конкурентный INSERT ждёт и падает по 1205,
    // после ROLLBACK повторный INSERT проходит.

    public function testConcurrentInsertWaitsForLockThenPassesAfterRollback(): void
    {
        $ref = self::uniqRef();

        $conn1 = Database::connect();
        $conn2 = Database::connect();
        $conn1->exec('SET SESSION innodb_lock_wait_timeout = 10');
        $conn2->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $conn1->exec('BEGIN');
        $this->insertApplication($conn1, $ref);

        $waitFailed = false;
        try {
            $this->insertApplication($conn2, $ref);
        } catch (PDOException $exception) {
            $waitFailed = ((int) ($exception->errorInfo[1] ?? 0)) === 1205;
        }

        $conn1->exec('ROLLBACK');

        self::assertTrue($waitFailed, 'конкурентный INSERT должен был упасть по lock wait timeout (1205)');
        $this->insertApplication($conn2, $ref);
        self::assertSame(1, $this->activeCount($ref));
    }

    // AC-4, фаза фиксации: после COMMIT первой транзакции конкурентный INSERT получает 1062,
    // активная строка ровно одна.

    public function testConcurrentInsertFailsWithDuplicateAfterCommit(): void
    {
        $ref = self::uniqRef();

        $conn1 = Database::connect();
        $conn2 = Database::connect();

        $conn1->exec('BEGIN');
        $this->insertApplication($conn1, $ref);
        $conn1->exec('COMMIT');

        $duplicateRejected = false;
        try {
            $this->insertApplication($conn2, $ref);
        } catch (PDOException $exception) {
            $duplicateRejected = ((int) ($exception->errorInfo[1] ?? 0)) === 1062;
        }

        self::assertTrue($duplicateRejected, 'конкурентный INSERT должен был получить дубликат (1062)');
        self::assertSame(1, $this->activeCount($ref));
    }
}
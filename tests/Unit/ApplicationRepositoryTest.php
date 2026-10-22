<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Repository\ApplicationRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Учебный двойник PDO для тестов репозитория: INSERT в applications падает с заданным кодом MySQL.
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

final class ApplicationRepositoryTest extends TestCase
{
    /** @return array{vin:string,year:int,mileage:int,market_value:int,requested_amount:int,term_months:int} */
    private static function input(): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 96000,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
        ];
    }

    /** @return array{ltv:float,decision:string,approved_limit:int} */
    private static function assessment(): array
    {
        return ['ltv' => 50.0, 'decision' => 'approve', 'approved_limit' => 450000];
    }

    public function testSaveTranslatesUniqueViolation1062ToDuplicateActiveApplicationException(): void
    {
        $repository = new ApplicationRepository(new UniqueViolationPdo([1062, 'Duplicate entry']));

        $this->expectException(DuplicateActiveApplicationException::class);
        $this->expectExceptionMessage((new DuplicateActiveApplicationException('CL-ANON'))->getMessage());

        $repository->save('CL-TST-UNIT', self::input(), self::assessment());
    }

    public function testDuplicateExceptionCarriesApplicantRef(): void
    {
        $repository = new ApplicationRepository(new UniqueViolationPdo([1062, 'Duplicate entry']));

        try {
            $repository->save('CL-TST-UNIT', self::input(), self::assessment());
            self::fail('ожидалось DuplicateActiveApplicationException');
        } catch (DuplicateActiveApplicationException $exception) {
            self::assertSame('CL-TST-UNIT', $exception->applicantRef());
        }
    }

    public function testSaveRethrowsOtherPdoErrorsAsIs(): void
    {
        $repository = new ApplicationRepository(new UniqueViolationPdo([1048, 'Column cannot be null']));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Column cannot be null');

        $repository->save('CL-TST-UNIT', self::input(), self::assessment());
    }
}
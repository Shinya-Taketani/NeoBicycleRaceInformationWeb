<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Exceptions\RetiredPlayerProfileNotRetiredException;
use App\Domain\Keirin\Scraping\Parsers\RetiredPlayerDetailParser;
use PHPUnit\Framework\TestCase;

class RetiredPlayerDetailParserTest extends TestCase
{
    public function test_it_parses_a_retired_profile_by_named_headers(): void
    {
        $sourceUrl = 'https://keirin.example.test/pc/racerprofile?snum=012345';
        $dto = (new RetiredPlayerDetailParser)->parse(
            $this->fixture(),
            $sourceUrl,
            '012345',
        );

        $this->assertSame('012345', $dto->externalPlayerId);
        $this->assertSame('012345', $dto->registrationNumber);
        $this->assertSame('合成　太郎', $dto->name);
        $this->assertSame('合成県', $dto->prefecture);
        $this->assertSame(53, $dto->age);
        $this->assertSame('71', $dto->graduationPeriod);
        $this->assertSame('A3', $dto->grade);
        $this->assertSame('2025-01-31', $dto->retiredOn->format('Y-m-d'));
        $this->assertSame('2025-07-01 02:34', $dto->sourceUpdatedAt?->format('Y-m-d H:i'));
        $this->assertSame($sourceUrl, $dto->sourceUrl);
    }

    public function test_it_rejects_invalid_profile_table_structures(): void
    {
        $headers = ['氏名', '府県', '年齢', '期別', '級班', '登録番号'];
        $values = ['合成　太郎', '合成県', '53歳', '71期', 'A級3班', '012345'];
        $cases = [
            'table missing' => '<html><body><p>本選手は、2025年01月31日に引退しました。</p></body></html>',
            'header missing' => $this->profileHtml(array_slice($headers, 0, 5), array_slice($values, 0, 5)),
            'data row missing' => $this->profileHtml($headers, null),
            'name missing' => $this->profileHtml($headers, ['', ...array_slice($values, 1)]),
            'registration missing' => $this->profileHtml($headers, [...array_slice($values, 0, 5), '']),
            'registration non-numeric' => $this->profileHtml($headers, [...array_slice($values, 0, 5), 'ABCDEF']),
            'registration five digits' => $this->profileHtml($headers, [...array_slice($values, 0, 5), '12345']),
            'registration seven digits' => $this->profileHtml($headers, [...array_slice($values, 0, 5), '0123456']),
            'multiple candidate tables' => $this->profileHtml($headers, $values, tableCopies: 2),
            'duplicate header' => $this->profileHtml(
                [...$headers, '氏名'],
                [...$values, '別名'],
            ),
        ];

        foreach ($cases as $case => $html) {
            try {
                (new RetiredPlayerDetailParser)->parse($html, 'https://example.test/profile', '012345');
                $this->fail("ParserException was not thrown for {$case}.");
            } catch (ParserException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_an_expected_registration_number_mismatch(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('did not match expected ID');

        (new RetiredPlayerDetailParser)->parse(
            $this->fixture(),
            'https://example.test/profile',
            '065432',
        );
    }

    public function test_it_rejects_profiles_without_a_strict_retirement_message(): void
    {
        foreach ([null, '本選手は現役です。', '本選手は、2025年01月31日に引退予定です。'] as $message) {
            try {
                (new RetiredPlayerDetailParser)->parse(
                    $this->profileHtml(
                        ['氏名', '府県', '年齢', '期別', '級班', '登録番号'],
                        ['合成　太郎', '合成県', '53歳', '71期', 'A級3班', '012345'],
                        retiredMessage: $message,
                    ),
                    'https://example.test/profile',
                    '012345',
                );
                $this->fail('A non-retired profile was parsed as retired.');
            } catch (RetiredPlayerProfileNotRetiredException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_an_invalid_retirement_date(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('date was invalid');

        (new RetiredPlayerDetailParser)->parse(
            $this->profileHtml(
                ['氏名', '府県', '年齢', '期別', '級班', '登録番号'],
                ['合成　太郎', '合成県', '53歳', '71期', 'A級3班', '012345'],
                retiredMessage: '本選手は、2025年02月30日に引退しました。',
            ),
            'https://example.test/profile',
            '012345',
        );
    }

    private function fixture(): string
    {
        return (string) file_get_contents(
            __DIR__.'/../../../../Fixtures/Keirin/synthetic/player-detail-retired.html',
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  null|list<string>  $values
     */
    private function profileHtml(
        array $headers,
        ?array $values,
        ?string $retiredMessage = '本選手は、2025年01月31日に引退しました。',
        int $tableCopies = 1,
    ): string {
        $headerHtml = implode('', array_map(fn (string $value): string => "<td>{$value}</td>", $headers));
        $valueHtml = $values === null
            ? ''
            : '<tr>'.implode('', array_map(fn (string $value): string => "<td>{$value}</td>", $values)).'</tr>';
        $table = "<table><tbody><tr>{$headerHtml}</tr>{$valueHtml}</tbody></table>";

        return '<!doctype html><html><head><meta charset="UTF-8"></head><body>'
            .'<p>2025/07/01 02:34 更新</p>'
            .str_repeat($table, $tableCopies)
            .($retiredMessage === null ? '' : "<p>{$retiredMessage}</p>")
            .'</body></html>';
    }
}

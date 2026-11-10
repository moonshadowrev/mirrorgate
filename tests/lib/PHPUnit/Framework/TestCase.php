<?php
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace PHPUnit\Framework;

/**
 * Small PHPUnit TestCase subset, loaded only when real PHPUnit is absent. It implements the
 * assertions the MirrorGate test suite uses so that tests/run.php works without Composer.
 */
abstract class TestCase
{
    public static int $assertions = 0;

    private ?string $expectedException = null;
    private ?int $expectedCode = null;

    public static function setUpBeforeClass(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    /** @internal Used by the runner. */
    final public function runBare(string $method, array $args): void
    {
        $this->expectedException = null;
        $this->expectedCode = null;
        $this->setUp();
        try {
            try {
                $this->{$method}(...$args);
            } catch (SkippedTest | AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($this->expectedException !== null && $e instanceof $this->expectedException) {
                    self::$assertions++;
                    if ($this->expectedCode !== null && $e->getCode() !== $this->expectedCode) {
                        throw new AssertionFailedError('Expected exception code ' . $this->expectedCode . ', got ' . $e->getCode());
                    }
                    $this->expectedException = null;
                    return;
                }
                throw $e;
            }
            if ($this->expectedException !== null) {
                throw new AssertionFailedError('Expected exception ' . $this->expectedException . ' was not thrown');
            }
        } finally {
            $this->tearDown();
        }
    }

    public function expectException(string $class): void
    {
        $this->expectedException = $class;
    }

    public function expectExceptionCode(int $code): void
    {
        $this->expectedCode = $code;
    }

    public static function markTestSkipped(string $message = ''): never
    {
        throw new SkippedTest($message);
    }

    public static function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message);
    }

    public function addToAssertionCount(int $count): void
    {
        self::$assertions += $count;
    }

    private static function check(bool $ok, string $message, string $detail): void
    {
        self::$assertions++;
        if (!$ok) {
            throw new AssertionFailedError(($message !== '' ? $message . "\n" : '') . $detail);
        }
    }

    private static function d(mixed $v): string
    {
        $s = var_export($v, true);
        return strlen($s) > 600 ? substr($s, 0, 600) . '...' : $s;
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected === $actual, $message, 'Failed asserting that ' . self::d($actual) . ' is identical to ' . self::d($expected));
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected !== $actual, $message, 'Failed asserting that value is not identical to ' . self::d($expected));
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::check($expected == $actual, $message, 'Failed asserting that ' . self::d($actual) . ' equals ' . self::d($expected));
    }

    public static function assertTrue(mixed $v, string $message = ''): void
    {
        self::check($v === true, $message, 'Failed asserting that ' . self::d($v) . ' is true');
    }

    public static function assertFalse(mixed $v, string $message = ''): void
    {
        self::check($v === false, $message, 'Failed asserting that ' . self::d($v) . ' is false');
    }

    public static function assertNull(mixed $v, string $message = ''): void
    {
        self::check($v === null, $message, 'Failed asserting that ' . self::d($v) . ' is null');
    }

    public static function assertNotNull(mixed $v, string $message = ''): void
    {
        self::check($v !== null, $message, 'Failed asserting that value is not null');
    }

    public static function assertNotFalse(mixed $v, string $message = ''): void
    {
        self::check($v !== false, $message, 'Failed asserting that value is not false');
    }

    public static function assertEmpty(mixed $v, string $message = ''): void
    {
        self::check(empty($v), $message, 'Failed asserting that ' . self::d($v) . ' is empty');
    }

    public static function assertNotEmpty(mixed $v, string $message = ''): void
    {
        self::check(!empty($v), $message, 'Failed asserting that value is not empty');
    }

    /** @param \Countable|array<mixed> $c */
    public static function assertCount(int $n, \Countable|array $c, string $message = ''): void
    {
        self::check(count($c) === $n, $message, 'Failed asserting that count ' . count($c) . ' matches expected ' . $n . ' in ' . self::d($c));
    }

    public static function assertLessThan(int|float $e, int|float $a, string $message = ''): void
    {
        self::check($a < $e, $message, "Failed asserting that $a is less than $e");
    }

    public static function assertLessThanOrEqual(int|float $e, int|float $a, string $message = ''): void
    {
        self::check($a <= $e, $message, "Failed asserting that $a is less than or equal to $e");
    }

    public static function assertGreaterThan(int|float $e, int|float $a, string $message = ''): void
    {
        self::check($a > $e, $message, "Failed asserting that $a is greater than $e");
    }

    public static function assertGreaterThanOrEqual(int|float $e, int|float $a, string $message = ''): void
    {
        self::check($a >= $e, $message, "Failed asserting that $a is greater than or equal to $e");
    }

    /** @param iterable<mixed> $h */
    public static function assertContains(mixed $needle, iterable $h, string $message = ''): void
    {
        $found = false;
        foreach ($h as $v) {
            if ($v === $needle) {
                $found = true;
                break;
            }
        }
        self::check($found, $message, 'Failed asserting that ' . self::d($h) . ' contains ' . self::d($needle));
    }

    /** @param iterable<mixed> $h */
    public static function assertNotContains(mixed $needle, iterable $h, string $message = ''): void
    {
        $found = false;
        foreach ($h as $v) {
            if ($v === $needle) {
                $found = true;
                break;
            }
        }
        self::check(!$found, $message, 'Failed asserting that ' . self::d($h) . ' does not contain ' . self::d($needle));
    }

    public static function assertStringContainsString(string $needle, string $h, string $message = ''): void
    {
        self::check(str_contains($h, $needle), $message, 'Failed asserting that ' . self::d($h) . ' contains ' . self::d($needle));
    }

    public static function assertStringNotContainsString(string $needle, string $h, string $message = ''): void
    {
        self::check(!str_contains($h, $needle), $message, 'Failed asserting that ' . self::d($h) . ' does not contain ' . self::d($needle));
    }

    public static function assertStringStartsWith(string $p, string $s, string $message = ''): void
    {
        self::check(str_starts_with($s, $p), $message, 'Failed asserting that ' . self::d($s) . ' starts with ' . self::d($p));
    }

    public static function assertStringEndsWith(string $p, string $s, string $message = ''): void
    {
        self::check(str_ends_with($s, $p), $message, 'Failed asserting that ' . self::d($s) . ' ends with ' . self::d($p));
    }

    public static function assertMatchesRegularExpression(string $re, string $s, string $message = ''): void
    {
        self::check(preg_match($re, $s) === 1, $message, 'Failed asserting that ' . self::d($s) . ' matches ' . $re);
    }

    public static function assertDoesNotMatchRegularExpression(string $re, string $s, string $message = ''): void
    {
        self::check(preg_match($re, $s) === 0, $message, 'Failed asserting that ' . self::d($s) . ' does not match ' . $re);
    }

    public static function assertIsArray(mixed $v, string $message = ''): void
    {
        self::check(is_array($v), $message, 'Failed asserting that value is an array');
    }

    public static function assertIsString(mixed $v, string $message = ''): void
    {
        self::check(is_string($v), $message, 'Failed asserting that value is a string');
    }

    public static function assertIsBool(mixed $v, string $message = ''): void
    {
        self::check(is_bool($v), $message, 'Failed asserting that value is a bool');
    }

    public static function assertIsInt(mixed $v, string $message = ''): void
    {
        self::check(is_int($v), $message, 'Failed asserting that value is an int');
    }

    public static function assertIsResource(mixed $v, string $message = ''): void
    {
        self::check(is_resource($v), $message, 'Failed asserting that value is a resource');
    }

    /** @param class-string $class */
    public static function assertInstanceOf(string $class, mixed $v, string $message = ''): void
    {
        self::check($v instanceof $class, $message, 'Failed asserting that value is an instance of ' . $class);
    }

    public static function assertFileExists(string $f, string $message = ''): void
    {
        self::check(file_exists($f), $message, "Failed asserting that file $f exists");
    }

    public static function assertFileDoesNotExist(string $f, string $message = ''): void
    {
        self::check(!file_exists($f), $message, "Failed asserting that file $f does not exist");
    }

    /** @param array<mixed> $a */
    public static function assertArrayHasKey(int|string $k, array $a, string $message = ''): void
    {
        self::check(array_key_exists($k, $a), $message, 'Failed asserting that array has key ' . $k);
    }

    /** @param array<mixed> $a */
    public static function assertArrayNotHasKey(int|string $k, array $a, string $message = ''): void
    {
        self::check(!array_key_exists($k, $a), $message, 'Failed asserting that array does not have key ' . $k);
    }
}

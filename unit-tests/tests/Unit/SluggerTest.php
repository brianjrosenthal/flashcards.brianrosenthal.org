<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SluggerTest extends TestCase
{
    public function testFromTextLowercasesAndHyphenates(): void
    {
        $this->assertSame('derivation-of-e-x', Slugger::fromText('Derivation of e^x!'));
        $this->assertSame('algebra-ii', Slugger::fromText('  Algebra II  '));
        $this->assertSame('sequences-series', Slugger::fromText('Sequences & Series'));
        $this->assertSame('', Slugger::fromText('!!!'));
    }

    public function testFromTextTransliteratesAccents(): void
    {
        $this->assertSame('cafe-francais', Slugger::fromText('Café Français'));
    }

    public function testFromTextTruncatesToMaxLength(): void
    {
        $slug = Slugger::fromText(str_repeat('word ', 40));
        $this->assertLessThanOrEqual(Slugger::MAX_LENGTH, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Slugger::isValid('algebra-ii'));
        $this->assertTrue(Slugger::isValid('e-x'));
        $this->assertFalse(Slugger::isValid('Algebra'));
        $this->assertFalse(Slugger::isValid('-leading'));
        $this->assertFalse(Slugger::isValid('double--hyphen'));
        $this->assertFalse(Slugger::isValid(''));
        $this->assertFalse(Slugger::isValid('has space'));
    }

    public function testReservedNamesAndRealPathsAreRefused(): void
    {
        $this->assertTrue(Slugger::isReserved('admin'));
        $this->assertTrue(Slugger::isReserved('MANAGE'));
        $this->assertTrue(Slugger::isReserved('login'), 'login.php exists in the web root');
        $this->assertTrue(Slugger::isReserved('public_site'), 'public_site.php exists in the web root');
        $this->assertTrue(Slugger::isReserved('www'), 'a page slug is also its subdomain');
        $this->assertTrue(Slugger::isReserved('mail'));
        foreach (['review', 'quiz', 'progress', 'cards', 'images', 'flashcards', 'study', 'site'] as $word) {
            $this->assertTrue(Slugger::isReserved($word), "$word would shadow an app path");
        }
        $this->assertFalse(Slugger::isReserved('styles'), 'styles.css does not shadow /styles/');
        $this->assertFalse(Slugger::isReserved('algebra-ii'));
    }

    public function testProblemWithExplainsFailures(): void
    {
        $this->assertNull(Slugger::problemWith('algebra-ii'));
        $this->assertStringContainsString('lowercase', (string)Slugger::problemWith('Algebra'));
        $this->assertStringContainsString('reserved', (string)Slugger::problemWith('admin'));
    }

    public function testMakeUniqueAppendsCounter(): void
    {
        $taken = ['algebra', 'algebra-2'];
        $exists = static fn(string $s): bool => in_array($s, $taken, true);
        $this->assertSame('algebra-3', Slugger::makeUnique('algebra', $exists));
        $this->assertSame('geometry', Slugger::makeUnique('geometry', $exists));
    }

    public function testMakeUniqueKeepsWithinMaxLength(): void
    {
        $base = str_repeat('a', Slugger::MAX_LENGTH);
        $exists = static fn(string $s): bool => $s === $base;
        $unique = Slugger::makeUnique($base, $exists);
        $this->assertLessThanOrEqual(Slugger::MAX_LENGTH, strlen($unique));
        $this->assertStringEndsWith('-2', $unique);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../www/lib/ManageUI.php';

final class ManageUINextForCardTest extends TestCase {
    public function testStudyUrlsArePointedAtTheOtherCard(): void {
        $this->assertSame('/review/study.php?subcategory=4&card=9', ManageUI::nextForCard('/review/study.php?subcategory=4&card=8', 9));
        $this->assertSame('/review/study.php?subcategory=4&card=9', ManageUI::nextForCard('/review/study.php?subcategory=4', 9));
        $this->assertSame('/review/study.php?card=9', ManageUI::nextForCard('/review/study.php?card=8', 9));
        $this->assertSame('/review/study.php?subcategory=4&filter=flagged&card=9', ManageUI::nextForCard('/review/study.php?card=8&subcategory=4&filter=flagged', 9));
    }

    public function testOtherUrlsAreUntouched(): void {
        $this->assertSame('/manage/cards.php?subcategory_id=4#card-8', ManageUI::nextForCard('/manage/cards.php?subcategory_id=4#card-8', 9));
        $this->assertSame('', ManageUI::nextForCard('', 9));
    }
}

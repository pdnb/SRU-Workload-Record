<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ThaiValidationMessagesTest extends TestCase
{
    public function test_required_message_is_in_thai_with_attribute_label(): void
    {
        $validator = Validator::make(
            ['groupName' => ''],
            ['groupName' => ['required']],
        );

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'กรุณากรอก ชื่อกลุ่ม',
            $validator->errors()->first('groupName'),
        );
    }

    public function test_min_string_message_uses_thai_for_work_entry_detail(): void
    {
        $validator = Validator::make(
            ['modalEntry' => ['detail' => 'สั้น']],
            ['modalEntry.detail' => ['required', 'string', 'min:10']],
        );

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'รายละเอียดการดำเนินงาน ต้องมีความยาวอย่างน้อย 10 ตัวอักษร',
            $validator->errors()->first('modalEntry.detail'),
        );
    }

    public function test_custom_evidence_upload_messages_are_in_thai(): void
    {
        $validator = Validator::make(
            ['evidenceUploads' => []],
            ['evidenceUploads' => ['required', 'array']],
        );

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'กรุณาเลือกไฟล์หลักฐานอย่างน้อย 1 ไฟล์',
            $validator->errors()->first('evidenceUploads'),
        );
    }

    public function test_after_date_message_is_in_thai(): void
    {
        $validator = Validator::make(
            [
                'periodStartDate' => '2026-06-01',
                'periodEndDate' => '2026-05-01',
            ],
            ['periodEndDate' => ['required', 'date', 'after:periodStartDate']],
        );

        $this->assertFalse($validator->passes());
        $message = $validator->errors()->first('periodEndDate');

        $this->assertStringContainsString('วันสิ้นสุด', $message);
        $this->assertStringContainsString('ต้องเป็นวันที่หลัง', $message);
        $this->assertStringNotContainsString('The ', $message);
    }
}

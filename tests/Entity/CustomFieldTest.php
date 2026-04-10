<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\CustomField;
use PHPUnit\Framework\TestCase;

class CustomFieldTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $field = new CustomField();
        $field->setName('Priority Level');
        $field->setSlug('priority-level');
        $field->setFieldType(CustomField::TYPE_SELECT);
        $field->setDescription('Custom priority');
        $field->setIsRequired(true);
        $field->setOptions(['low', 'medium', 'high']);
        $field->setDefaultValue('medium');
        $field->setEntityType('ticket');
        $field->setPosition(1);
        $field->setIsActive(true);

        $this->assertSame('Priority Level', $field->getName());
        $this->assertSame('priority-level', $field->getSlug());
        $this->assertSame(CustomField::TYPE_SELECT, $field->getFieldType());
        $this->assertSame('Custom priority', $field->getDescription());
        $this->assertTrue($field->isRequired());
        $this->assertSame(['low', 'medium', 'high'], $field->getOptions());
        $this->assertSame('medium', $field->getDefaultValue());
        $this->assertSame('ticket', $field->getEntityType());
        $this->assertSame(1, $field->getPosition());
        $this->assertTrue($field->isActive());
    }

    public function testFieldTypeConstants(): void
    {
        $this->assertContains('text', CustomField::TYPES);
        $this->assertContains('select', CustomField::TYPES);
        $this->assertContains('checkbox', CustomField::TYPES);
        $this->assertContains('date', CustomField::TYPES);
        $this->assertCount(8, CustomField::TYPES);
    }
}

<?php

namespace Tests\Unit;

use App\Models\InquiryForm;
use PHPUnit\Framework\TestCase;

class InquiryFormSubmissionWorkflowTest extends TestCase
{
    public function test_form_workflow_overrides_page_and_old_forms_keep_page_fallback(): void
    {
        $this->assertSame('corporate', InquiryForm::resolveSubmissionWorkflow(['submission_workflow' => 'corporate'], 'general'));
        $this->assertSame('point_to_point', InquiryForm::resolveSubmissionWorkflow([], 'point_to_point'));
        $this->assertSame('general', InquiryForm::resolveSubmissionWorkflow([]));
    }
}

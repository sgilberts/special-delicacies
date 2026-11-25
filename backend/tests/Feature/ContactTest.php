<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_accepts_valid_submission(): void
    {
        $response = $this->postJson('/api/v1/contact', [
            'name'    => 'Jane Doe',
            'email'   => 'jane@example.com',
            'subject' => 'Reservation Enquiry',
            'message' => 'I would like to book a table for four on Saturday evening.',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }

    public function test_contact_form_rejects_missing_required_fields(): void
    {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Jane Doe',
            // missing email, subject, message
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false])
                 ->assertJsonValidationErrors(['email', 'subject', 'message']);
    }

    public function test_contact_form_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/v1/contact', [
            'name'    => 'Jane Doe',
            'email'   => 'not-an-email',
            'subject' => 'Hello',
            'message' => 'Testing the form submission here.',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_contact_form_accepts_optional_phone(): void
    {
        $response = $this->postJson('/api/v1/contact', [
            'name'    => 'John Smith',
            'email'   => 'john@example.com',
            'phone'   => '+233 20 123 4567',
            'subject' => 'Menu Question',
            'message' => 'Do you have vegetarian options available on your menu?',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true]);
    }
}

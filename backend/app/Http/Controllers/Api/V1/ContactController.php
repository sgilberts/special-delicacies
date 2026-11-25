<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    /**
     * Handle a public contact form submission.
     * Phase 3 — stores to log. Full notification system is a later phase.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'    => ['required', 'string', 'max:150'],
            'email'   => ['required', 'email:rfc', 'max:200'],
            'phone'   => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the highlighted fields.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        Log::channel('stack')->info('Contact form submission received', [
            'name'    => $data['name'],
            'email'   => $data['email'],
            'subject' => $data['subject'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your message. We will be in touch shortly.',
        ]);
    }
}

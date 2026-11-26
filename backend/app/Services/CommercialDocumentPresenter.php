<?php

namespace App\Services;

use App\Models\CommercialDocument;

class CommercialDocumentPresenter
{
    public function __construct(private readonly PaymentAllocationService $paymentAllocations) {}

    public function present(CommercialDocument $document, bool $includeFinancials = true): array
    {
        $document->loadMissing(['lines', 'customer:id,name,email,phone', 'organisation:id,name,reference']);
        $data = [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'document_type' => $document->document_type,
            'status' => $document->status,
            'customer' => $document->customer ? ['id' => $document->customer->id, 'name' => $document->customer->name, 'email' => $document->customer->email, 'phone' => $document->customer->phone] : null,
            'organisation' => $document->organisation ? ['id' => $document->organisation->id, 'name' => $document->organisation->name, 'reference' => $document->organisation->reference] : null,
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date?->toDateString(),
            'currency' => $document->currency,
            'subtotal' => $document->subtotal,
            'discount_amount' => $document->discount_amount,
            'discount_description' => $document->discount_description,
            'tax_amount' => $document->tax_amount,
            'total' => $document->total,
            'notes' => $document->notes,
            'terms' => $document->terms,
            'void_reason' => $document->void_reason,
            'lines' => $document->lines->map(fn ($line) => [
                'id' => $line->id, 'menu_item_id' => $line->menu_item_id, 'description' => $line->description,
                'detail' => $line->detail, 'unit_of_measure' => $line->unit_of_measure, 'quantity' => $line->quantity,
                'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount,
                'line_subtotal' => $line->line_subtotal, 'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount, 'line_total' => $line->line_total,
            ])->values(),
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
        ];
        if ($document->document_type === CommercialDocument::TYPE_INVOICE && $includeFinancials) {
            $data['payment_summary'] = $this->paymentAllocations->summaryForDocument($document);
        }
        return $data;
    }
}

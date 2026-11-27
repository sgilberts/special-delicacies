<?php

use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\AdminRolePermissionController;
use App\Http\Controllers\Api\V1\Admin\AdminStaffController;
use App\Http\Controllers\Api\V1\Admin\AdminTableQrController;
use App\Http\Controllers\Api\V1\Admin\AdminWebsiteContentController;
use App\Http\Controllers\Api\V1\Admin\DeliveryZoneController;
use App\Http\Controllers\Api\V1\Admin\MenuCategoryController;
use App\Http\Controllers\Api\V1\Admin\RestaurantTableController;
use App\Http\Controllers\Api\V1\Admin\MenuItemController;
use App\Http\Controllers\Api\V1\Admin\MenuOverviewController;
use App\Http\Controllers\Api\V1\Admin\MenuPriceController;
use App\Http\Controllers\Api\V1\Admin\RestaurantSettingController;
use App\Http\Controllers\Api\V1\Admin\ReverbController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AccountVerificationController;
use App\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerCreditController;
use App\Http\Controllers\Api\V1\CustomerCommercialDocumentController;
use App\Http\Controllers\Api\V1\OrganisationController;
use App\Http\Controllers\Api\V1\OrganisationInvitationController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\DeliveryOrderController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\Admin\AdminPaymentGatewayController;
use App\Http\Controllers\Api\V1\Admin\FinancialController;
use App\Http\Controllers\Api\V1\Admin\PaymentAllocationController;
use App\Http\Controllers\Api\V1\Admin\CustomerCreditController as AdminCustomerCreditController;
use App\Http\Controllers\Api\V1\Admin\CommercialDocumentController;
use App\Http\Controllers\Api\V1\Admin\RefundController;
use App\Http\Controllers\Api\V1\Admin\CreditNoteController;
use App\Http\Controllers\Api\V1\Admin\CreditReversalController;
use App\Http\Controllers\Api\V1\Admin\ChatAdminController;
use App\Http\Controllers\Api\V1\PublicContentController;
use App\Http\Controllers\Api\V1\PublicDeliveryZoneController;
use App\Http\Controllers\Api\V1\TableQrController;
use App\Http\Middleware\EnsureCustomerAccount;
use App\Http\Middleware\EnsureStaffAccount;
use App\Http\Middleware\EnsureOrganisationPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Health check endpoint (Preserved from Phase 1)
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'Special Delicacies API is running.',
            'application' => 'Special Delicacies',
            'version' => '1.0.0',
        ]);
    });

    // Public Authentication (rate-limited: 5 attempts per minute per email+IP)
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/customers/register', [CustomerController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/customers/login', [CustomerController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/password/forgot', [PasswordRecoveryController::class, 'requestStaffEmail'])->middleware('throttle:5,1');
    Route::post('/auth/password/phone/forgot', [PasswordRecoveryController::class, 'requestStaffPhone'])->middleware('throttle:5,1');
    Route::post('/auth/password/reset', [PasswordRecoveryController::class, 'resetStaffEmail'])->middleware('throttle:10,1');
    Route::post('/auth/password/phone/reset', [PasswordRecoveryController::class, 'resetStaffPhone'])->middleware('throttle:10,1');
    Route::post('/customers/password/forgot', [PasswordRecoveryController::class, 'requestCustomerEmail'])->middleware('throttle:5,1');
    Route::post('/customers/password/phone/forgot', [PasswordRecoveryController::class, 'requestCustomerPhone'])->middleware('throttle:5,1');
    Route::post('/customers/password/reset', [PasswordRecoveryController::class, 'resetCustomerEmail'])->middleware('throttle:10,1');
    Route::post('/customers/password/phone/reset', [PasswordRecoveryController::class, 'resetCustomerPhone'])->middleware('throttle:10,1');

    // Authenticated user endpoint (Preserved from Phase 1)
    Route::get('/user', function (Request $request) {
        return $request->user();
    })->middleware(['auth:sanctum', EnsureStaffAccount::class]);

    // Public / Customer Foundations
    Route::get('/menu', [MenuController::class, 'index']);
    Route::post('/contact', [ContactController::class, 'store']);
    Route::get('/table-lookup/{token}', [TableQrController::class, 'resolve']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/payment-options', [PaymentController::class, 'options']);
    Route::get('/payments/verify/{reference}', [PaymentController::class, 'verify'])->middleware('throttle:20,1');
    Route::get('/orders/track/{orderNumber}', [PaymentController::class, 'track'])->middleware('throttle:20,1');
    Route::post('/payments/paystack/webhook', [PaymentController::class, 'webhook'])->middleware('throttle:120,1');
    Route::post('/payments/{provider}/webhook', [PaymentController::class, 'providerWebhook'])->middleware('throttle:120,1');

    Route::middleware(['auth:sanctum', EnsureCustomerAccount::class])->prefix('customer')->group(function () {
        Route::get('/profile', [CustomerController::class, 'profile']);
        Route::patch('/profile', [CustomerController::class, 'updateProfile']);
        Route::get('/orders', [CustomerController::class, 'orders']);
        Route::post('/logout', [CustomerController::class, 'logout']);
        Route::post('/verification/{channel}/request', [AccountVerificationController::class, 'requestCustomer'])->middleware('throttle:5,1');
        Route::post('/verification/{channel}/verify', [AccountVerificationController::class, 'verifyCustomer'])->middleware('throttle:10,1');
        Route::get('/quotations', [CustomerCommercialDocumentController::class, 'index'])->defaults('type', 'quotation');
        Route::get('/quotations/{commercialDocument}', [CustomerCommercialDocumentController::class, 'show'])->defaults('type', 'quotation');
        Route::get('/quotations/{commercialDocument}/history', [CustomerCommercialDocumentController::class, 'history'])->defaults('type', 'quotation');
        Route::post('/quotations/{commercialDocument}/accept', [CustomerCommercialDocumentController::class, 'accept'])->defaults('type', 'quotation');
        Route::post('/quotations/{commercialDocument}/decline', [CustomerCommercialDocumentController::class, 'decline'])->defaults('type', 'quotation');
        Route::get('/invoices', [CustomerCommercialDocumentController::class, 'index'])->defaults('type', 'invoice');
        Route::get('/invoices/{commercialDocument}', [CustomerCommercialDocumentController::class, 'show'])->defaults('type', 'invoice');
        Route::get('/invoices/{commercialDocument}/history', [CustomerCommercialDocumentController::class, 'history'])->defaults('type', 'invoice');
        Route::get('/credit', [CustomerCreditController::class, 'index']);
        Route::get('/invitations', [OrganisationInvitationController::class, 'inbox']);
        Route::post('/invitations/{invitation}/accept', [OrganisationInvitationController::class, 'accept']);

        Route::get('/organisations', [OrganisationController::class, 'index']);
        Route::post('/organisations', [OrganisationController::class, 'store'])->middleware('throttle:10,1');
        Route::prefix('organisations/{organisation}')->group(function () {
            Route::get('/', [OrganisationController::class, 'show'])->middleware(EnsureOrganisationPermission::class.':view');
            Route::patch('/', [OrganisationController::class, 'update'])->middleware(EnsureOrganisationPermission::class.':profile.update');
            Route::patch('/status', [OrganisationController::class, 'updateStatus'])->middleware(EnsureOrganisationPermission::class.':status.manage');
            Route::get('/members', [OrganisationController::class, 'members'])->middleware(EnsureOrganisationPermission::class.':members.view');
            Route::get('/invitations', [OrganisationInvitationController::class, 'organisationIndex'])->middleware(EnsureOrganisationPermission::class.':members.manage');
            Route::post('/invitations', [OrganisationInvitationController::class, 'invite'])->middleware([EnsureOrganisationPermission::class.':members.manage', 'throttle:10,1']);
            Route::delete('/invitations/{invitation}', [OrganisationInvitationController::class, 'cancel'])->middleware(EnsureOrganisationPermission::class.':members.manage');
            Route::patch('/members/{membership}/role', [OrganisationController::class, 'updateMemberRole'])->middleware(EnsureOrganisationPermission::class.':members.roles.manage');
            Route::patch('/members/{membership}/financial-access', [OrganisationController::class, 'updateMemberFinancialAccess'])->middleware(EnsureOrganisationPermission::class.':members.roles.manage');
            Route::patch('/members/{membership}/status', [OrganisationController::class, 'updateMemberStatus'])->middleware(EnsureOrganisationPermission::class.':members.manage');
        });
    });

    // Visitor chat uses a random client-held capability; the server stores only its SHA-256 hash.
    Route::post('/chat/conversations', [ChatController::class, 'start'])->middleware('throttle:5,1');
    Route::get('/chat/availability', [ChatController::class, 'availability'])->middleware('throttle:60,1');
    Route::get('/chat/conversations/{publicId}/messages', [ChatController::class, 'messages'])->middleware('throttle:60,1');
    Route::post('/chat/conversations/{publicId}/receipts', [ChatController::class, 'acknowledge'])->middleware('throttle:120,1');
    Route::post('/chat/conversations/{publicId}/messages', [ChatController::class, 'send'])->middleware('throttle:20,1');
    Route::post('/chat/conversations/{publicId}/typing', [ChatController::class, 'typing'])->middleware('throttle:30,1');
    Route::get('/chat/conversations/{publicId}/messages/{messageId}/attachment', [ChatController::class, 'attachment'])->name('chat.visitor.attachment')->middleware('throttle:60,1');
    Route::get('/chat/conversations/{publicId}/messages/{messageId}/thumbnail', [ChatController::class, 'thumbnail'])->name('chat.visitor.thumbnail')->middleware('throttle:60,1');

    // Public delivery zones (no auth required — customers pick zone during checkout)
    Route::get('/delivery-zones', [PublicDeliveryZoneController::class, 'index']);

    // Public website content & branding
    Route::get('/content', [PublicContentController::class, 'index']);

    // Authenticated & Role/Permission Protected Endpoints
    Route::middleware(['auth:sanctum', EnsureStaffAccount::class])->group(function () {
        Route::post('/auth/verification/email/request', [AccountVerificationController::class, 'requestStaffEmail'])->middleware('throttle:5,1');
        Route::post('/auth/verification/email/verify', [AccountVerificationController::class, 'verifyStaffEmail'])->middleware('throttle:10,1');
        Route::post('/auth/verification/phone/request', [AccountVerificationController::class, 'requestStaffPhone'])->middleware('throttle:5,1');
        Route::post('/auth/verification/phone/verify', [AccountVerificationController::class, 'verifyStaffPhone'])->middleware('throttle:10,1');
        // Auth session endpoints
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/profile', [AuthController::class, 'updateProfile']);

        Route::prefix('admin/chat')->group(function () {
            Route::get('/settings', [ChatAdminController::class, 'settings'])->middleware('permission:chat.manage_settings');
            Route::put('/settings', [ChatAdminController::class, 'updateSettings'])->middleware('permission:chat.manage_settings');
            Route::post('/presence/heartbeat', [ChatAdminController::class, 'heartbeat'])->middleware('permission:chat.receive');
            Route::get('/eligible-staff', [ChatAdminController::class, 'eligibleStaff'])->middleware('permission:chat.assign,chat.reassign');
            Route::get('/conversations', [ChatAdminController::class, 'index'])->middleware('permission:chat.view_assigned,chat.view_all');
            Route::get('/conversations/{id}', [ChatAdminController::class, 'show'])->middleware('permission:chat.view_assigned,chat.view_all');
            Route::post('/conversations/{id}/receipts', [ChatAdminController::class, 'acknowledge'])->middleware(['permission:chat.view_assigned,chat.view_all', 'throttle:120,1']);
            Route::get('/conversations/{id}/audit', [ChatAdminController::class, 'audit'])->middleware('permission:chat.view_audit_logs');
            Route::post('/conversations/{id}/messages', [ChatAdminController::class, 'send'])->middleware('permission:chat.reply');
            Route::post('/conversations/{id}/typing', [ChatAdminController::class, 'typing'])->middleware('permission:chat.reply');
            Route::patch('/conversations/{id}/assignment', [ChatAdminController::class, 'assign'])->middleware('permission:chat.assign,chat.reassign');
            Route::patch('/conversations/{id}/close', [ChatAdminController::class, 'close'])->middleware('permission:chat.close');
            Route::patch('/conversations/{id}/reopen', [ChatAdminController::class, 'reopen'])->middleware('permission:chat.close');
            Route::delete('/messages/{messageId}', [ChatAdminController::class, 'destroyMessage'])->middleware('permission:chat.delete_message');
            Route::get('/messages/{messageId}/attachment', [ChatAdminController::class, 'attachment'])->name('chat.staff.attachment')->middleware('permission:chat.view_assigned,chat.view_all');
            Route::get('/messages/{messageId}/thumbnail', [ChatAdminController::class, 'thumbnail'])->name('chat.staff.thumbnail')->middleware('permission:chat.view_assigned,chat.view_all');
        });

        // Order management (Preserved from Phase 2 & Enhanced for Phase 7 Operations)
        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware('permission:orders.view');
        Route::get('/orders/{id}', [OrderController::class, 'show'])
            ->middleware('permission:orders.view');
        Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])
            ->middleware('permission:orders.status.update,orders.confirm,orders.prepare,orders.ready,orders.deliver,orders.complete,orders.cancel');

        // Administrative Menu Price Management (Preserved from Phase 2; Waiters restricted)
        Route::put('/admin/menu/items/{id}/price', [MenuPriceController::class, 'update'])
            ->middleware('permission:menu.items.update_price,menu.price.edit,menu.manage');

        // Restaurant Settings (Preserved from Phase 2; Kitchen staff restricted)
        Route::get('/admin/settings', [RestaurantSettingController::class, 'index'])
            ->middleware('permission:settings.view,settings.manage');
        Route::put('/admin/settings', [RestaurantSettingController::class, 'update'])
            ->middleware('permission:settings.manage');
        Route::get('/admin/payment-gateway', [AdminPaymentGatewayController::class, 'show'])
            ->middleware('permission:settings.view,settings.manage');
        Route::put('/admin/payment-gateway', [AdminPaymentGatewayController::class, 'update'])
            ->middleware('permission:settings.manage');
        Route::get('/admin/payment-gateways', [AdminPaymentGatewayController::class, 'index'])
            ->middleware('permission:settings.view,settings.manage');
        Route::post('/admin/payment-gateways', [AdminPaymentGatewayController::class, 'store'])
            ->middleware('permission:settings.manage');
        Route::put('/admin/payment-gateways/{id}', [AdminPaymentGatewayController::class, 'updateRecord'])
            ->middleware('permission:settings.manage');
        Route::put('/admin/payment-gateways/{id}/default', [AdminPaymentGatewayController::class, 'setDefault'])
            ->middleware('permission:settings.manage');
        Route::delete('/admin/payment-gateways/{id}', [AdminPaymentGatewayController::class, 'destroy'])
            ->middleware('permission:settings.manage');

        // Phase 8: Delivery Orders — Delivery Staff Operational Routes
        Route::prefix('delivery')->group(function () {
            Route::get('/orders', [DeliveryOrderController::class, 'index'])
                ->middleware('permission:delivery.orders.view,orders.view');
            Route::get('/orders/{id}', [DeliveryOrderController::class, 'show'])
                ->middleware('permission:delivery.orders.view,orders.view');
            Route::post('/orders/{id}/assign', [DeliveryOrderController::class, 'assign'])
                ->middleware('permission:delivery.orders.assign');
            Route::patch('/orders/{id}/status', [DeliveryOrderController::class, 'updateStatus'])
                ->middleware('permission:delivery.orders.dispatch,delivery.orders.deliver,orders.cancel');
            Route::post('/orders/{id}/confirm-payment', [DeliveryOrderController::class, 'confirmPayment'])
                ->middleware('permission:delivery.orders.deliver,orders.complete,orders.status.update');
        });

        // Phase 8: Admin Delivery Zone Management
        Route::prefix('admin/delivery-zones')->group(function () {
            Route::get('/', [DeliveryZoneController::class, 'index'])
                ->middleware('permission:delivery_zones.view,delivery_zones.manage');
            Route::post('/', [DeliveryZoneController::class, 'store'])
                ->middleware('permission:delivery_zones.manage');
            Route::get('/{id}', [DeliveryZoneController::class, 'show'])
                ->middleware('permission:delivery_zones.view,delivery_zones.manage');
            Route::put('/{id}', [DeliveryZoneController::class, 'update'])
                ->middleware('permission:delivery_zones.manage');
            Route::patch('/{id}/status', [DeliveryZoneController::class, 'toggleStatus'])
                ->middleware('permission:delivery_zones.manage');
            Route::delete('/{id}', [DeliveryZoneController::class, 'destroy'])
                ->middleware('permission:delivery_zones.manage');
        });

        // Phase 7: Real-Time Operational Broadcasting & Reverb Service Monitor
        Route::prefix('admin/reverb')->group(function () {
            Route::get('/status', [ReverbController::class, 'status'])
                ->middleware('permission:reverb.monitor,settings.manage');
            Route::post('/start', [ReverbController::class, 'start'])
                ->middleware('permission:settings.manage');
            Route::post('/stop', [ReverbController::class, 'stop'])
                ->middleware('permission:settings.manage');
            Route::post('/restart', [ReverbController::class, 'restart'])
                ->middleware('permission:settings.manage');
            Route::post('/test-broadcast', [ReverbController::class, 'testBroadcast'])
                ->middleware('permission:reverb.monitor,settings.manage');
        });

        // Phase 4: Administrative Menu Overview & Statistics
        Route::get('/admin/menu/overview', [MenuOverviewController::class, 'index'])
            ->middleware('permission:menu.categories.view,menu.items.view,menu.manage');

        // Phase 4: Menu Categories Management
        Route::prefix('admin/menu/categories')->group(function () {
            Route::get('/', [MenuCategoryController::class, 'index'])
                ->middleware('permission:menu.categories.view,menu.manage');
            Route::post('/', [MenuCategoryController::class, 'store'])
                ->middleware('permission:menu.categories.create,menu.manage');
            Route::get('/{id}', [MenuCategoryController::class, 'show'])
                ->middleware('permission:menu.categories.view,menu.manage');
            Route::put('/{id}', [MenuCategoryController::class, 'update'])
                ->middleware('permission:menu.categories.update,menu.manage');
            Route::patch('/{id}/status', [MenuCategoryController::class, 'toggleStatus'])
                ->middleware('permission:menu.categories.update,menu.manage');
            Route::delete('/{id}', [MenuCategoryController::class, 'destroy'])
                ->middleware('permission:menu.categories.delete,menu.manage');
        });

        // Phase 4: Menu Items Management
        Route::prefix('admin/menu/items')->group(function () {
            Route::get('/', [MenuItemController::class, 'index'])
                ->middleware('permission:menu.items.view,menu.manage');
            Route::post('/', [MenuItemController::class, 'store'])
                ->middleware('permission:menu.items.create,menu.manage');
            Route::get('/{id}', [MenuItemController::class, 'show'])
                ->middleware('permission:menu.items.view,menu.manage');
            Route::post('/{id}', [MenuItemController::class, 'update'])
                ->middleware('permission:menu.items.update,menu.manage');
            Route::put('/{id}', [MenuItemController::class, 'update'])
                ->middleware('permission:menu.items.update,menu.manage');
            Route::patch('/{id}/availability', [MenuItemController::class, 'toggleAvailability'])
                ->middleware('permission:menu.items.update_availability,menu.manage');
            Route::delete('/{id}/image', [MenuItemController::class, 'removeImage'])
                ->middleware('permission:menu.items.upload_image,menu.manage');
            Route::delete('/{id}', [MenuItemController::class, 'destroy'])
                ->middleware('permission:menu.items.delete,menu.manage');
        });

        // Phase 5: Restaurant Table Management
        // IMPORTANT: /admin/tables/qr MUST come before /admin/tables/{id}
        Route::get('/admin/tables/qr', [AdminTableQrController::class, 'index'])
            ->middleware('permission:tables.qr.view,tables.manage');
        Route::get('/admin/tables', [RestaurantTableController::class, 'index'])
            ->middleware('permission:tables.view,tables.manage');
        Route::post('/admin/tables', [RestaurantTableController::class, 'store'])
            ->middleware('permission:tables.create,tables.manage');
        Route::get('/admin/tables/{id}', [RestaurantTableController::class, 'show'])
            ->middleware('permission:tables.view,tables.manage');
        Route::put('/admin/tables/{id}', [RestaurantTableController::class, 'update'])
            ->middleware('permission:tables.update,tables.manage');
        Route::patch('/admin/tables/{id}/status', [RestaurantTableController::class, 'toggleStatus'])
            ->middleware('permission:tables.update,tables.manage');
        Route::delete('/admin/tables/{id}', [RestaurantTableController::class, 'destroy'])
            ->middleware('permission:tables.delete,tables.manage');
        Route::get('/admin/tables/{tableId}/qr', [AdminTableQrController::class, 'show'])
            ->middleware('permission:tables.qr.view,tables.manage');
        Route::post('/admin/tables/{tableId}/qr/generate', [AdminTableQrController::class, 'generate'])
            ->middleware('permission:tables.qr.generate,tables.manage');
        Route::post('/admin/tables/{tableId}/qr/regenerate', [AdminTableQrController::class, 'regenerate'])
            ->middleware('permission:tables.qr.generate,tables.manage');
        Route::delete('/admin/tables/{tableId}/qr/revoke', [AdminTableQrController::class, 'revoke'])
            ->middleware('permission:tables.qr.revoke,tables.manage');
        Route::delete('/admin/tables/qr/{id}', [AdminTableQrController::class, 'destroy'])
            ->middleware('permission:tables.qr.revoke,tables.manage');

        // Phase 9: Central Administration Dashboard
        Route::get('/admin/dashboard/stats', [AdminDashboardController::class, 'stats'])
            ->middleware('permission:orders.view,staff.view,settings.view');

        // Phase 10A: financial management with explicit financial permissions.
        Route::prefix('admin/financials')->group(function () {
            Route::get('/snapshot', [FinancialController::class, 'snapshot'])
                ->middleware('permission:financials.view');
            Route::get('/reports', [FinancialController::class, 'snapshot'])
                ->middleware('permission:financials.reports.view');
            Route::get('/transactions', [FinancialController::class, 'transactions'])
                ->middleware('permission:financials.transactions.view');
            Route::get('/transactions/{id}', [FinancialController::class, 'showTransaction'])
                ->middleware('permission:financials.transactions.view');
            Route::get('/reconciliation', [FinancialController::class, 'transactions'])
                ->middleware('permission:financials.reconciliation.view');
            Route::get('/export.csv', [FinancialController::class, 'export'])
                ->middleware('permission:financials.reports.export');
            Route::get('/payment-allocations/payments', [PaymentAllocationController::class, 'payments'])
                ->middleware('permission:financials.allocations.view,financials.allocations.manage');
            Route::get('/payment-allocations/payments/{paymentId}', [PaymentAllocationController::class, 'payment'])
                ->middleware('permission:financials.allocations.view,financials.allocations.manage');
            Route::get('/payment-allocations/payments/{paymentId}/invoices', [PaymentAllocationController::class, 'invoices'])
                ->middleware('permission:financials.allocations.view,financials.allocations.manage');
            Route::get('/payment-allocations/invoices/{commercialDocument}/balance', [PaymentAllocationController::class, 'invoiceBalance'])
                ->middleware('permission:financials.invoice_balances.view,financials.allocations.view,financials.allocations.manage');
            Route::get('/payment-allocations/invoices/{commercialDocument}', [PaymentAllocationController::class, 'invoiceAllocations'])
                ->middleware('permission:financials.allocations.view,financials.allocations.manage');
            Route::post('/payment-allocations', [PaymentAllocationController::class, 'store'])
                ->middleware(['permission:financials.allocations.manage', 'throttle:30,1']);
            Route::post('/payment-allocations/preview', [PaymentAllocationController::class, 'preview'])
                ->middleware(['permission:financials.allocations.manage', 'throttle:60,1']);
            Route::get('/payment-allocations/history', [PaymentAllocationController::class, 'history'])
                ->middleware('permission:financials.allocations.view,financials.allocations.manage');
            Route::post('/payment-allocations/{paymentAllocation}/reverse', [PaymentAllocationController::class, 'reverse'])
                ->middleware(['permission:financials.allocations.reverse', 'throttle:20,1']);
        });

        Route::prefix('admin/customer-credit')->group(function () {
            Route::get('/receipts', [AdminCustomerCreditController::class, 'receipts'])->middleware('permission:financials.deposits.view,financials.deposits.manage');
            Route::get('/invoices', [AdminCustomerCreditController::class, 'invoices'])->middleware('permission:financials.customer_credit.view,financials.customer_credit.manage');
            Route::post('/receipts/{paymentId}/intent', [AdminCustomerCreditController::class, 'classify'])->middleware(['permission:financials.deposits.manage', 'throttle:20,1']);
            Route::get('/balances', [AdminCustomerCreditController::class, 'balances'])->middleware('permission:financials.customer_credit.view,financials.customer_credit.manage');
            Route::get('/ledger', [AdminCustomerCreditController::class, 'ledger'])->middleware('permission:financials.customer_credit.view,financials.customer_credit.manage');
            Route::post('/designations', [AdminCustomerCreditController::class, 'designate'])->middleware(['permission:financials.customer_credit.manage', 'throttle:20,1']);
            Route::post('/applications', [AdminCustomerCreditController::class, 'apply'])->middleware(['permission:financials.customer_credit.manage', 'throttle:20,1']);
        });

        // Stage 4C: Refunds
        Route::prefix('admin/financials/refunds')->group(function () {
            $viewRefunds = 'permission:financials.refunds.view,financials.refunds.initiate,financials.refunds.approve';
            Route::get('/eligible-payments', [RefundController::class, 'eligiblePayments'])
                ->middleware('permission:financials.refunds.view,financials.refunds.initiate');
            Route::get('/', [RefundController::class, 'index'])->middleware($viewRefunds);
            Route::post('/', [RefundController::class, 'store'])
                ->middleware(['permission:financials.refunds.initiate', 'throttle:20,1']);
            Route::get('/{refund}', [RefundController::class, 'show'])->middleware($viewRefunds);
            Route::post('/{refund}/approve', [RefundController::class, 'approve'])
                ->middleware(['permission:financials.refunds.approve', 'throttle:20,1']);
            Route::post('/{refund}/reject', [RefundController::class, 'reject'])
                ->middleware(['permission:financials.refunds.approve', 'throttle:20,1']);
            Route::post('/{refund}/submit', [RefundController::class, 'submit'])
                ->middleware(['permission:financials.refunds.approve', 'throttle:20,1']);
            Route::post('/{refund}/confirm', [RefundController::class, 'confirm'])
                ->middleware(['permission:financials.refunds.approve', 'throttle:20,1']);
            Route::post('/{refund}/reconcile', [RefundController::class, 'reconcile'])
                ->middleware(['permission:financials.refunds.approve', 'throttle:20,1']);
            Route::post('/{refund}/fail', [RefundController::class, 'fail'])
                ->middleware(['permission:financials.refunds.approve', 'throttle:20,1']);
        });

        // Stage 4C: Credit notes
        Route::prefix('admin/financials/credit-notes')->group(function () {
            $viewNotes = 'permission:financials.credit_notes.view,financials.credit_notes.create,financials.credit_notes.approve';
            Route::get('/eligible-invoices', [CreditNoteController::class, 'eligibleInvoices'])
                ->middleware('permission:financials.credit_notes.view,financials.credit_notes.create');
            Route::get('/', [CreditNoteController::class, 'index'])->middleware($viewNotes);
            Route::post('/', [CreditNoteController::class, 'store'])
                ->middleware(['permission:financials.credit_notes.create', 'throttle:20,1']);
            Route::get('/{creditNote}', [CreditNoteController::class, 'show'])->middleware($viewNotes);
            Route::post('/{creditNote}/issue', [CreditNoteController::class, 'issue'])
                ->middleware(['permission:financials.credit_notes.approve', 'throttle:20,1']);
            Route::post('/{creditNote}/void', [CreditNoteController::class, 'void'])
                ->middleware(['permission:financials.credit_notes.approve', 'throttle:20,1']);
        });

        // Stage 4C: Customer credit reversals
        Route::prefix('admin/financials/credit-reversals')->group(function () {
            $viewReversals = 'permission:financials.credit_reversals.view,financials.credit_reversals.initiate,financials.credit_reversals.approve';
            Route::get('/eligible', [CreditReversalController::class, 'eligible'])
                ->middleware('permission:financials.credit_reversals.view,financials.credit_reversals.initiate');
            Route::get('/', [CreditReversalController::class, 'index'])->middleware($viewReversals);
            Route::get('/{ledgerEntry}/preview', [CreditReversalController::class, 'preview'])->middleware($viewReversals);
            Route::post('/', [CreditReversalController::class, 'store'])
                ->middleware(['permission:financials.credit_reversals.initiate', 'throttle:20,1']);
            Route::post('/{creditReversal}/approve', [CreditReversalController::class, 'approve'])
                ->middleware(['permission:financials.credit_reversals.approve', 'throttle:20,1']);
            Route::post('/{creditReversal}/reject', [CreditReversalController::class, 'reject'])
                ->middleware(['permission:financials.credit_reversals.approve', 'throttle:20,1']);
        });

        // Phase 9: Staff & User Management
        Route::prefix('admin/staff')->group(function () {
            Route::get('/', [AdminStaffController::class, 'index'])
                ->middleware('permission:staff.view,staff.manage');
            Route::get('/roles', [AdminStaffController::class, 'assignableRoles'])
                ->middleware('permission:staff.manage');
            Route::post('/', [AdminStaffController::class, 'store'])
                ->middleware('permission:staff.manage');
            Route::get('/{id}', [AdminStaffController::class, 'show'])
                ->middleware('permission:staff.view,staff.manage');
            Route::put('/{id}', [AdminStaffController::class, 'update'])
                ->middleware('permission:staff.manage');
            Route::patch('/{id}/status', [AdminStaffController::class, 'toggleStatus'])
                ->middleware('permission:staff.manage');
            Route::post('/{id}/reset-password', [AdminStaffController::class, 'resetPassword'])
                ->middleware(['permission:staff.manage', 'throttle:10,1']);
        });

        // Phase 9: Role & Permission Management
        Route::prefix('admin/roles')->group(function () {
            Route::get('/', [AdminRolePermissionController::class, 'index'])
                ->middleware('permission:roles.manage');
            Route::post('/', [AdminRolePermissionController::class, 'store'])
                ->middleware('permission:roles.manage');
            Route::put('/{id}', [AdminRolePermissionController::class, 'update'])
                ->middleware('permission:roles.manage');
            Route::delete('/{id}', [AdminRolePermissionController::class, 'destroy'])
                ->middleware('permission:roles.manage');
            Route::put('/{id}/permissions', [AdminRolePermissionController::class, 'updatePermissions'])
                ->middleware('permission:roles.manage');
        });
        Route::get('/admin/permissions', [AdminRolePermissionController::class, 'permissions'])
            ->middleware('permission:roles.manage');

        // Phase 9: Administrative Order Oversight
        Route::prefix('admin/orders')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index'])
                ->middleware('permission:orders.view');
            Route::get('/{id}', [AdminOrderController::class, 'show'])
                ->middleware('permission:orders.view');
            Route::post('/{id}/confirm-payment', [AdminOrderController::class, 'confirmPayment'])
                ->middleware('permission:financials.payments.confirm_cash');
            Route::patch('/{id}/status', [AdminOrderController::class, 'updateStatus'])
                ->middleware('permission:orders.status.update,orders.confirm,orders.prepare,orders.ready,orders.deliver,orders.complete,orders.cancel');
        });

        Route::prefix('admin/commercial-documents')->group(function () {
            Route::get('/', [CommercialDocumentController::class, 'index'])->middleware('permission:commercial_documents.view');
            Route::post('/', [CommercialDocumentController::class, 'store'])->middleware('permission:commercial_documents.manage');
            Route::get('/{commercialDocument}', [CommercialDocumentController::class, 'show'])->middleware('permission:commercial_documents.view');
            Route::get('/{commercialDocument}/history', [CommercialDocumentController::class, 'history'])->middleware('permission:commercial_documents.view');
            Route::patch('/{commercialDocument}', [CommercialDocumentController::class, 'update'])->middleware('permission:commercial_documents.manage');
            Route::post('/{commercialDocument}/issue', [CommercialDocumentController::class, 'issue'])->middleware('permission:commercial_documents.issue');
            Route::post('/{commercialDocument}/cancel', [CommercialDocumentController::class, 'cancel'])->middleware('permission:commercial_documents.manage');
            Route::post('/{commercialDocument}/void', [CommercialDocumentController::class, 'void'])->middleware('permission:commercial_documents.void');
        });

        // Phase 9: Restaurant Website Content Management
        Route::prefix('admin/content')->group(function () {
            Route::get('/', [AdminWebsiteContentController::class, 'index'])
                ->middleware('permission:content.manage,settings.view');
            Route::put('/', [AdminWebsiteContentController::class, 'update'])
                ->middleware('permission:content.manage');
            Route::post('/gallery-image', [AdminWebsiteContentController::class, 'uploadGalleryImage'])
                ->middleware('permission:content.manage');
            Route::post('/logo', [AdminWebsiteContentController::class, 'uploadLogo'])
                ->middleware('permission:content.manage,settings.manage');
            Route::post('/favicon', [AdminWebsiteContentController::class, 'uploadFavicon'])
                ->middleware('permission:content.manage,settings.manage');
        });

        // Phase 9: Administrative Audit Trail
        Route::get('/admin/audit-logs', [AdminAuditLogController::class, 'index'])
            ->middleware('permission:audit.view,staff.manage');
    });
});

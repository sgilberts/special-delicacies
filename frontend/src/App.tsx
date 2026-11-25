import { Routes, Route, Navigate } from "react-router-dom";
import { lazy, Suspense } from "react";
import { useLocation } from "react-router-dom";
import ChatWidget from "@/components/public/ChatWidget";
import PublicLayout from "@/components/public/PublicLayout";
const HomePage = lazy(() => import("@/pages/public/HomePage"));
const AboutPage = lazy(() => import("@/pages/public/AboutPage"));
const MenuPage = lazy(() => import("@/pages/public/MenuPage"));
const GalleryPage = lazy(() => import("@/pages/public/GalleryPage"));
const ContactPage = lazy(() => import("@/pages/public/ContactPage"));
const PaymentResultPage = lazy(() => import("@/pages/public/PaymentResultPage"));
const OrderTrackingPage = lazy(() => import("@/pages/public/OrderTrackingPage"));

// Admin Imports (Phase 4 Menu Management & Phase 5 Tables/QR)
import AdminLayout from "@/components/admin/AdminLayout";
import { ProtectedRoute } from "@/components/admin/ProtectedRoute";
const AdminLoginPage = lazy(() => import("@/pages/admin/AdminLoginPage"));
const AdminMenuOverviewPage = lazy(() => import("@/pages/admin/AdminMenuOverviewPage"));
const AdminCategoriesPage = lazy(() => import("@/pages/admin/AdminCategoriesPage"));
const AdminMenuItemsPage = lazy(() => import("@/pages/admin/AdminMenuItemsPage"));
const AdminTablesPage = lazy(() => import("@/pages/admin/AdminTablesPage"));
const AdminQrCodesPage = lazy(() => import("@/pages/admin/AdminQrCodesPage"));
const WaiterDashboard = lazy(() => import("@/pages/admin/WaiterDashboard"));
const KitchenDashboard = lazy(() => import("@/pages/admin/KitchenDashboard"));
const AdminReverbPage = lazy(() => import("@/pages/admin/AdminReverbPage"));
const QrLandingPage = lazy(() => import("@/pages/public/QrLandingPage"));

// Phase 8: Home Delivery
const DeliveryPage = lazy(() => import("@/pages/public/DeliveryPage"));
const DeliveryCheckoutPage = lazy(() => import("@/pages/public/DeliveryCheckoutPage"));
const DeliveryConfirmationPage = lazy(() => import("@/pages/public/DeliveryConfirmationPage"));
const DeliveryDashboard = lazy(() => import("@/pages/admin/DeliveryDashboard"));
const AdminDeliveryZonesPage = lazy(() => import("@/pages/admin/AdminDeliveryZonesPage"));

// Phase 9: Administration
const AdminDashboardPage = lazy(() => import("@/pages/admin/AdminDashboardPage"));
const AdminStaffPage = lazy(() => import("@/pages/admin/AdminStaffPage"));
const AdminRolesPage = lazy(() => import("@/pages/admin/AdminRolesPage"));
const AdminOrdersPage = lazy(() => import("@/pages/admin/AdminOrdersPage"));
const AdminSettingsPage = lazy(() => import("@/pages/admin/AdminSettingsPage"));
const AdminContentPage = lazy(() => import("@/pages/admin/AdminContentPage"));
const AdminAuditLogsPage = lazy(() => import("@/pages/admin/AdminAuditLogsPage"));
const AdminProfilePage = lazy(() => import("@/pages/admin/AdminProfilePage"));
const AdminFinancialsPage = lazy(() => import("@/pages/admin/AdminFinancialsPage"));
const AdminChatPage = lazy(() => import("@/pages/admin/AdminChatPage"));
const AdminChatSettingsPage = lazy(() => import("@/pages/admin/AdminChatSettingsPage"));

function App() {
  const location = useLocation();
  return (
    <>
    <Suspense fallback={<div className="min-h-[50vh] p-6 text-center text-sm text-[var(--color-text-secondary)]">Loading page…</div>}>
    <Routes>
      {/* Public Restaurant Website */}
      <Route path="/" element={<PublicLayout />}>
        <Route index element={<HomePage />} />
        <Route path="about" element={<AboutPage />} />
        <Route path="menu" element={<MenuPage />} />
        <Route path="gallery" element={<GalleryPage />} />
        <Route path="contact" element={<ContactPage />} />
      </Route>

      {/* Phase 8: Public Delivery Ordering (outside PublicLayout for full-screen UX) */}
      <Route path="/delivery" element={<DeliveryPage />} />
      <Route path="/delivery/checkout" element={<DeliveryCheckoutPage />} />
      <Route path="/delivery/confirmation/:orderNumber" element={<DeliveryConfirmationPage />} />
      <Route path="/payment/result" element={<PaymentResultPage />} />
      <Route path="/track-order" element={<OrderTrackingPage />} />

      {/* Admin Authentication */}
      <Route path="/admin/login" element={<AdminLoginPage />} />

      {/* Admin Management Shell */}
      <Route
        path="/admin"
        element={
          <ProtectedRoute>
            <AdminLayout />
          </ProtectedRoute>
        }
      >
        {/* Phase 9 Central Dashboard as /admin index */}
        <Route index element={<AdminDashboardPage />} />

        {/* Phase 4 & 5 Menu and Tables Management */}
        <Route path="menu-overview" element={<AdminMenuOverviewPage />} />
        <Route path="categories" element={<AdminCategoriesPage />} />
        <Route path="items" element={<AdminMenuItemsPage />} />
        <Route path="tables" element={<AdminTablesPage />} />
        <Route path="qr-codes" element={<AdminQrCodesPage />} />

        {/* Phase 7 & 8 Operations */}
        <Route path="waiter" element={<WaiterDashboard />} />
        <Route path="kitchen" element={<KitchenDashboard />} />
        <Route path="reverb" element={<AdminReverbPage />} />
        <Route path="delivery" element={<DeliveryDashboard />} />
        <Route path="delivery-zones" element={<AdminDeliveryZonesPage />} />

        {/* Phase 9 Administration Deliverables */}
        <Route
          path="staff"
          element={
            <ProtectedRoute requiredPermissions={["staff.view", "staff.manage"]}>
              <AdminStaffPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="roles"
          element={
            <ProtectedRoute requiredPermissions={["roles.manage"]}>
              <AdminRolesPage />
            </ProtectedRoute>
          }
        />
        <Route path="orders" element={<AdminOrdersPage />} />
        <Route path="settings" element={<AdminSettingsPage />} />
        <Route path="content" element={<AdminContentPage />} />
        <Route path="audit-logs" element={<AdminAuditLogsPage />} />
        <Route path="profile" element={<AdminProfilePage />} />
        <Route path="financials" element={<AdminFinancialsPage />} />
        <Route path="chat" element={<AdminChatPage />} />
        <Route path="chat/settings" element={<AdminChatSettingsPage />} />
      </Route>

      {/* Public Table QR Landing Route */}
      <Route path="/table/:token" element={<QrLandingPage />} />
      <Route path="/table" element={<QrLandingPage />} />

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
    </Suspense>
    {!location.pathname.startsWith("/admin") && <ChatWidget />}
    </>
  );
}

export default App;

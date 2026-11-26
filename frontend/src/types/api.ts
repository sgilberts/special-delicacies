export interface MenuItem {
  id: number;
  category_id: number;
  name: string;
  slug: string;
  description: string | null;
  price: string;
  image_path: string | null;
  image_url?: string | null;
  is_available: boolean;
  sort_order: number;
  category?: {
    id: number;
    name: string;
    slug: string;
    is_active?: boolean;
  };
  created_at?: string;
  updated_at?: string;
}

export interface MenuCategory {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  sort_order: number;
  is_active: boolean;
  items_count?: number;
  items?: MenuItem[];
  available_items?: MenuItem[];
  created_at?: string;
  updated_at?: string;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
}

export interface PaginatedResponse<T> {
  success: boolean;
  data: T[];
  meta: PaginationMeta;
  message?: string;
}

export interface MenuOverviewStats {
  total_categories: number;
  active_categories: number;
  inactive_categories: number;
  total_items: number;
  available_items: number;
  unavailable_items: number;
  recent_items: MenuItem[];
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  email_verified_at?: string | null;
  phone_verified_at?: string | null;
  profile_photo_url?: string | null;
  is_active: boolean;
  roles: string[];
  permissions: string[];
}

export interface ContactFormData {
  name: string;
  email: string;
  phone?: string;
  subject: string;
  message: string;
}

export interface RestaurantTable {
  id: number;
  table_number: string;
  capacity: number;
  is_active: boolean;
  has_active_qr?: boolean;
  active_qr_code?: TableQrCode | null;
  created_at?: string;
  updated_at?: string;
}

export interface TableQrCode {
  id: number;
  restaurant_table_id: number;
  is_active: boolean;
  revoked_at: string | null;
  qr_url?: string | null;
  created_at?: string;
  updated_at?: string;
  table?: {
    id: number;
    table_number: string;
    capacity: number;
    is_active: boolean;
  };
}

export interface TableStats {
  total: number;
  active: number;
  inactive: number;
  with_qr: number;
}

export interface QrGenerateResult {
  token: string;
  qr_url: string;
  qr_id: number;
  table: {
    id: number;
    table_number: string;
    capacity: number;
    is_active: boolean;
  };
}

export interface CustomerCartItem {
  menu_item_id: number;
  item: MenuItem;
  quantity: number;
  special_instructions?: string;
}

export interface PlacedOrderItem {
  id: number;
  order_id: number;
  menu_item_id: number | null;
  item_name: string;
  unit_price: string;
  quantity: number;
  special_instructions?: string | null;
  line_total: string;
}

export interface PlacedOrder {
  id: number;
  order_number: string;
  order_type: "dine_in" | "delivery";
  restaurant_table_id: number | null;
  delivery_zone_id: number | null;
  delivery_zone?: DeliveryZone | null;
  delivery_address: string | null;
  customer_name: string;
  customer_phone: string;
  customer_notes?: string | null;
  status: string;
  subtotal: string;
  delivery_fee: string;
  total: string;
  payment_method: string;
  payment_status: string;
  payment_details?: PaymentDetails;
  payment_checkout_url?: string;
  items?: PlacedOrderItem[];
  table?: {
    id: number;
    table_number: string;
    capacity: number;
  } | null;
  status_histories?: OrderStatusHistory[];
  created_at: string;
}


export interface OrderItem {
  id: number;
  menu_item_id: number | null;
  item_name: string;
  unit_price: string;
  quantity: number;
  special_instructions: string | null;
  line_total: string;
}

export interface OrderStatusHistory {
  id: number;
  order_id: number;
  previous_status: string | null;
  new_status: string;
  changed_by: number | null;
  note: string | null;
  created_at: string;
}

export interface DineInOrder {
  id: number;
  order_number: string;
  order_type: 'dine_in' | 'delivery';
  status: string;
  restaurant_table_id: number | null;
  delivery_zone_id: number | null;
  delivery_zone?: DeliveryZone | null;
  delivery_address: string | null;
  table?: { id: number; table_number: string; capacity: number } | null;
  customer_name: string;
  customer_phone: string;
  customer_notes: string | null;
  subtotal: string;
  delivery_fee: string;
  total: string;
  payment_method: string;
  payment_status: string;
  payment_details?: PaymentDetails;
  waiter_id: number | null;
  kitchen_staff_id: number | null;
  delivery_staff_id: number | null;
  items: OrderItem[];
  status_histories?: OrderStatusHistory[];
  created_at: string;
  updated_at: string;
}

export interface PaymentDetails {
  method: string;
  status: string;
  gateway_name: string | null;
  gateway_provider: string | null;
  reference: string | null;
  amount: string;
  transaction_status?: string | null;
  currency?: string | null;
  verified_at?: string | null;
}

export interface DeliveryZone {
  id: number;
  name: string;
  description: string | null;
  delivery_fee: string;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

// Phase 9: Administration Types
export interface AdminDashboardStats {
  period: string;
  date_range: {
    from: string | null;
    to: string | null;
  };
  orders: {
    total: number;
    pending: number;
    confirmed: number;
    preparing: number;
    ready: number;
    out_for_delivery: number;
    delivered: number;
    completed: number;
    cancelled: number;
    active_total: number;
  };
  financials: {
    total_sales: string;
    currency: string;
    currency_symbol: string;
    cash_awaiting_confirmation_count: number;
    cash_awaiting_confirmation_amount: string;
  } | null;
  resources: {
    active_menu_items: number;
    total_menu_items: number;
    active_tables: number;
    total_tables: number;
    active_staff: number;
    total_staff: number;
  };
  breakdown: {
    delivery: {
      total: number;
      active: number;
    };
    dine_in: {
      total: number;
      active: number;
    };
  };
  recent_orders: DineInOrder[];
}

export interface StaffUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  roles?: {
    id: number;
    name: string;
    slug: string;
    description?: string;
    permissions?: PermissionItem[];
  }[];
  created_at?: string;
  updated_at?: string;
}

export interface RoleItem {
  id: number;
  name: string;
  slug: string;
  description: string;
  users_count?: number;
  permissions?: PermissionItem[];
}

export interface PermissionItem {
  id: number;
  name: string;
  slug: string;
  description: string;
  group?: string;
}

export interface AdminAuditLogItem {
  id: number;
  user_id: number | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  description: string;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  } | null;
}

export interface WebsiteGalleryItem {
  id: number;
  category: "Food" | "Restaurant" | "Events";
  title: string;
  description: string;
  tint?: string;
  image_url?: string | null;
  display_order?: number;
  is_published?: boolean;
  show_in_hero?: boolean;
  hero_active?: boolean;
  hero_sort_order?: number;
  hero_cta_text?: string | null;
  hero_cta_url?: string | null;
}

export interface WebsiteContentData {
  hero_title: string;
  hero_subtitle: string;
  hero_announcement: string | null;
  about_story: string | null;
  about_mission: string | null;
  opening_hours_days: string | null;
  restaurant_logo: string | null;
  favicon_url: string | null;
  gallery_items: WebsiteGalleryItem[];
}

export interface PublicContentResponse extends WebsiteContentData {
  hero_items: WebsiteGalleryItem[];
  restaurant_name: string;
  restaurant_phone: string;
  restaurant_email: string;
  restaurant_address: string;
  currency: string;
  currency_symbol: string;
  operating_hours: string;
  dine_in_enabled: boolean;
  delivery_enabled: boolean;
}


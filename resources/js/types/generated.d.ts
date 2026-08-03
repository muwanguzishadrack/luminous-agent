declare namespace App {
    namespace Data {
        export type TenantPermissions = {
            readonly canUpdateTenant: boolean;
            readonly canDeleteTenant: boolean;
            readonly canAddMember: boolean;
            readonly canUpdateMember: boolean;
            readonly canRemoveMember: boolean;
            readonly canCreateInvitation: boolean;
            readonly canCancelInvitation: boolean;
        };
        export type UserTenant = {
            readonly id: string;
            readonly name: string;
            readonly slug: string;
            readonly isPersonal: boolean;
            readonly role: string | null;
            readonly roleLabel: string | null;
            readonly isCurrent: boolean | null;
        };
    }
    namespace Enums {
        export type ActorType = 'user' | 'system' | 'mba' | 'owner_device';
        export type CampaignRouting = 'cloud_api' | 'mm_api';
        export type CampaignStatus =
            | 'draft'
            | 'scheduled'
            | 'queueing'
            | 'sending'
            | 'paused'
            | 'completed'
            | 'cancelled'
            | 'failed';
        export type ConsentScope =
            'marketing' | 'utility' | 'authentication' | 'all';
        export type ConsentSource =
            | 'whatsapp_native'
            | 'inbound_keyword'
            | 'web_form'
            | 'import'
            | 'agent'
            | 'api'
            | 'system';
        export type ConsentState = 'granted' | 'revoked';
        export type ConversationState = 'ai' | 'queued' | 'human' | 'closed';
        export type HealthSeverity = 'info' | 'warning' | 'critical';
        export type MediaScanStatus =
            'pending' | 'clean' | 'infected' | 'skipped';
        export type MessageDirection = 'inbound' | 'outbound';
        export type MessageOrigin =
            | 'agent'
            | 'mba'
            | 'campaign'
            | 'automation'
            | 'owner_device'
            | 'customer'
            | 'system';
        export type MessageStatus =
            'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'deleted';
        export type MetaCredentialType = 'business' | 'bisu' | 'system';
        export type OrderStatus =
            | 'draft'
            | 'pending_payment'
            | 'partially_paid'
            | 'paid'
            | 'fulfilling'
            | 'shipped'
            | 'completed'
            | 'cancelled'
            | 'refunded';
        export type PaymentDirection = 'collection' | 'disbursement';
        export type PaymentStatus =
            | 'Pending'
            | 'SentToVendor'
            | 'Success'
            | 'Failed'
            | 'AwaitingApproval'
            | 'RolledBack'
            | 'Scheduled'
            | 'Cancelled'
            | 'Rejected';
        export type TemplateCategory =
            'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
        export type TenantPermission =
            | 'tenant:update'
            | 'tenant:delete'
            | 'member:add'
            | 'member:update'
            | 'member:remove'
            | 'invitation:create'
            | 'invitation:cancel';
        export type TenantRole =
            'owner' | 'admin' | 'supervisor' | 'agent' | 'viewer';
        export type UsageBasis = 'estimate' | 'actual' | 'correction';
        export type UsageMeter =
            | 'template_message'
            | 'service_message'
            | 'mba_tokens'
            | 'platform_seat'
            | 'payment_fee';
        export type WebhookDeliveryStatus =
            'pending' | 'processed' | 'partial' | 'failed' | 'ignored';
        export type WebhookSource = 'meta' | 'iotec';
    }
}

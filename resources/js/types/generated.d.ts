declare namespace App {
    namespace Data {
        export type PendingInvitation = {
            readonly code: string;
            readonly inviterName: string;
            readonly teamName: string;
            readonly roleLabel: string;
        };
        export type TeamPermissions = {
            readonly canUpdateTeam: boolean;
            readonly canDeleteTeam: boolean;
            readonly canAddMember: boolean;
            readonly canUpdateMember: boolean;
            readonly canRemoveMember: boolean;
            readonly canCreateInvitation: boolean;
            readonly canCancelInvitation: boolean;
        };
        export type UserTeam = {
            readonly id: string;
            readonly name: string;
            readonly slug: string;
            readonly role: string | null;
            readonly roleLabel: string | null;
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
        export type TeamPermission =
            | 'team:update'
            | 'team:delete'
            | 'member:add'
            | 'member:update'
            | 'member:remove'
            | 'invitation:create'
            | 'invitation:cancel'
            | 'whatsapp:manage';
        export type TeamRole =
            'owner' | 'admin' | 'supervisor' | 'agent' | 'viewer';
        export type TemplateCategory =
            'MARKETING' | 'UTILITY' | 'AUTHENTICATION';
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
        export type WhatsAppVertical =
            | 'ALCOHOL'
            | 'APPAREL'
            | 'AUTO'
            | 'BEAUTY'
            | 'EDU'
            | 'ENTERTAIN'
            | 'EVENT_PLAN'
            | 'FINANCE'
            | 'GOVT'
            | 'GROCERY'
            | 'HEALTH'
            | 'HOTEL'
            | 'NONPROFIT'
            | 'ONLINE_GAMBLING'
            | 'OTC_DRUGS'
            | 'OTHER'
            | 'PHYSICAL_GAMBLING'
            | 'PROF_SERVICES'
            | 'RESTAURANT'
            | 'RETAIL'
            | 'TRAVEL';
    }
}

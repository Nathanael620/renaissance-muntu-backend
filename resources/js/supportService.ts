import { apiClient } from './apiClient';

export type DonationCheckoutPayload = {
    donor_name: string;
    donor_email: string;
    donor_address: string | null;
    donor_city: string | null;
    donor_province: string | null;
    donor_postal_code: string | null;
    donor_country: string | null;
    amount: number;
};

export type DonationCheckoutResponse = {
    message: string;
    checkout_url: string;
    reference: string;
};

export type PartnershipPayload = {
    name: string;
    organization: string | null;
    email: string;
    phone: string | null;
    partnership_type: string;
    message: string | null;
};

export type PartnershipResponse = {
    message: string;
    id: number;
};

export function createDonationCheckout(payload: DonationCheckoutPayload): Promise<DonationCheckoutResponse> {
    return apiClient.post<DonationCheckoutResponse>('/donations/checkout-session', payload);
}

export function submitPartnershipRequest(payload: PartnershipPayload): Promise<PartnershipResponse> {
    return apiClient.post<PartnershipResponse>('/partnerships', payload);
}

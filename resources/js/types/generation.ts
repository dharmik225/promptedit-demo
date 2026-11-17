export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface TokenBreakdown {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
}

export interface Generation {
    id: string;
    provider: string;
    provider_generation_id: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_image_urls: string[];
    status: GenerationStatus;
    status_label: string;
    is_in_progress: boolean;
    user?: { name: string; email: string };
    image_url: string | null;
    error: { code: string; message: string | null } | null;
    markup_percentage: string;
    rates: Record<string, string>;
    tokens: {
        estimated: TokenBreakdown;
        actual: TokenBreakdown | null;
    };
    costs: {
        estimated_provider_cost: string;
        estimated_user_cost: string;
        actual_provider_cost: string | null;
        actual_user_cost: string | null;
    };
    created_at: string | null;
    submitted_at: string | null;
    started_at: string | null;
    completed_at: string | null;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        per_page: number;
        links: PaginationLink[];
    };
}

export interface GenerationOptions {
    available: boolean;
    sizes: string[];
    qualities: string[];
    max_reference_images: number;
    max_prompt_length: number;
    markup_percentage: string;
}

export interface CostEstimateBound {
    tokens: TokenBreakdown;
    provider_cost: string;
    user_cost: string;
}

export interface CostEstimate {
    minimum: CostEstimateBound;
    maximum: CostEstimateBound;
    markup_percentage: string;
    rates: Record<string, string>;
}

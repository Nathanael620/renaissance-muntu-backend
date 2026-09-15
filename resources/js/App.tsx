import { FormEvent, ReactElement, useState } from 'react';
import { ApiError } from './apiClient';
import {
    createDonationCheckout,
    DonationCheckoutPayload,
    submitPartnershipRequest,
} from './supportService';

type View = 'home' | 'donate' | 'partnership';

type DonationForm = {
    donor_name: string;
    donor_email: string;
    donor_address: string;
    donor_city: string;
    donor_province: string;
    donor_postal_code: string;
    donor_country: string;
    amount: string;
};

type PartnershipForm = {
    name: string;
    organization: string;
    email: string;
    phone: string;
    partnership_type: string;
    message: string;
};

const initialDonation: DonationForm = {
    donor_name: '',
    donor_email: '',
    donor_address: '',
    donor_city: '',
    donor_province: '',
    donor_postal_code: '',
    donor_country: 'Canada',
    amount: '25',
};

const initialPartnership: PartnershipForm = {
    name: '',
    organization: '',
    email: '',
    phone: '',
    partnership_type: 'collaborative_project',
    message: '',
};

function currentView(): View {
    if (window.location.pathname.startsWith('/don')) return 'donate';
    if (window.location.pathname.startsWith('/partnership')) return 'partnership';
    return 'home';
}

function navigate(view: View): void {
    const path = view === 'donate' ? '/don' : view === 'partnership' ? '/partnership' : '/';
    window.history.pushState({}, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
}

function getErrorMessage(error: unknown): string {
    const apiError = error as ApiError;
    return apiError?.message ?? 'Une erreur est survenue. Veuillez réessayer.';
}

function App(): ReactElement {
    const [view, setView] = useState<View>(currentView);
    const [donation, setDonation] = useState<DonationForm>(initialDonation);
    const [partnership, setPartnership] = useState<PartnershipForm>(initialPartnership);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    window.onpopstate = () => setView(currentView());

    const updateDonation = (field: keyof DonationForm, value: string): void => {
        setDonation((current) => ({ ...current, [field]: value }));
    };

    const updatePartnership = (field: keyof PartnershipForm, value: string): void => {
        setPartnership((current) => ({ ...current, [field]: value }));
    };

    async function handleDonationSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setBusy(true);
        setNotice(null);

        try {
            const response = await createDonationCheckout({
                ...donation,
                donor_address: donation.donor_address || null,
                donor_city: donation.donor_city || null,
                donor_province: donation.donor_province || null,
                donor_postal_code: donation.donor_postal_code || null,
                donor_country: donation.donor_country || null,
                amount: Number(donation.amount),
            });

            window.location.assign(response.checkout_url);
        } catch (error) {
            setNotice(getErrorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    async function handlePartnershipSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setBusy(true);
        setNotice(null);

        try {
            await submitPartnershipRequest({
                ...partnership,
                organization: partnership.organization || null,
                phone: partnership.phone || null,
                message: partnership.message || null,
            });
            setPartnership(initialPartnership);
            setNotice('Votre demande de partenariat a été envoyée.');
        } catch (error) {
            setNotice(getErrorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    return (
        <main className="site-shell">
            <nav className="topbar" aria-label="Navigation principale">
                <button className="brand" onClick={() => { navigate('home'); setView('home'); }}>
                    <span className="brand-mark">RM</span>
                    <span>Renaissance Muntu</span>
                </button>
                <div className="nav-links">
                    <button onClick={() => { navigate('home'); setView('home'); }}>Accueil</button>
                    <button onClick={() => { navigate('partnership'); setView('partnership'); }}>Partenariat</button>
                    <button className="nav-cta" onClick={() => { navigate('donate'); setView('donate'); }}>Faire un don</button>
                </div>
            </nav>

            {notice && <div className="notice" role="status">{notice}</div>}

            {view === 'home' && (
                <section className="hero-grid">
                    <div className="hero-copy">
                        <p className="eyebrow">Mouvement culturel et citoyen</p>
                        <h1>Faire grandir le lien, une action à la fois.</h1>
                        <p className="hero-lede">Renaissance Muntu accompagne des initiatives qui donnent une place réelle aux mémoires, aux talents et aux projets de nos communautés.</p>
                        <div className="hero-actions">
                            <button className="primary-button" onClick={() => { navigate('donate'); setView('donate'); }}>Soutenir le mouvement</button>
                            <button className="text-button" onClick={() => { navigate('partnership'); setView('partnership'); }}>Construire avec nous <span aria-hidden="true">→</span></button>
                        </div>
                    </div>
                    <div className="hero-art" aria-label="Composition graphique Renaissance Muntu">
                        <span className="art-orbit orbit-one" />
                        <span className="art-orbit orbit-two" />
                        <span className="art-word">MUNTU</span>
                        <span className="art-caption">être humain<br />devenir ensemble</span>
                    </div>
                </section>
            )}

            {view === 'donate' && (
                <section className="form-layout">
                    <div className="form-intro">
                        <p className="eyebrow">Soutien direct</p>
                        <h1>Votre geste devient une ressource.</h1>
                        <p>Remplissez vos coordonnées pour ouvrir une session de paiement sécurisée Stripe. Le reçu officiel sera envoyé après confirmation du paiement.</p>
                        <div className="side-note"><strong>Confidentiel et sécurisé</strong><span>Vos informations servent uniquement au traitement du don et à l'émission du reçu.</span></div>
                    </div>
                    <form className="form-panel" onSubmit={handleDonationSubmit}>
                        <div className="form-heading"><span>01</span><h2>Vos informations</h2></div>
                        <div className="field-grid two-col">
                            <label>Nom complet<input required maxLength={255} value={donation.donor_name} onChange={(event) => updateDonation('donor_name', event.target.value)} /></label>
                            <label>Adresse e-mail<input required type="email" maxLength={255} value={donation.donor_email} onChange={(event) => updateDonation('donor_email', event.target.value)} /></label>
                        </div>
                        <div className="field-grid two-col">
                            <label>Adresse<input maxLength={255} value={donation.donor_address} onChange={(event) => updateDonation('donor_address', event.target.value)} /></label>
                            <label>Ville<input maxLength={255} value={donation.donor_city} onChange={(event) => updateDonation('donor_city', event.target.value)} /></label>
                            <label>Province<input maxLength={255} value={donation.donor_province} onChange={(event) => updateDonation('donor_province', event.target.value)} /></label>
                            <label>Code postal<input maxLength={20} value={donation.donor_postal_code} onChange={(event) => updateDonation('donor_postal_code', event.target.value)} /></label>
                        </div>
                        <div className="form-heading second"><span>02</span><h2>Votre contribution</h2></div>
                        <label className="amount-field">Montant du don <span className="amount-input"><input required min="5" step="0.01" type="number" value={donation.amount} onChange={(event) => updateDonation('amount', event.target.value)} /><b>CAD</b></span></label>
                        <button className="primary-button full-width" disabled={busy}>{busy ? 'Préparation...' : 'Continuer vers Stripe →'}</button>
                        <p className="form-footnote">Le paiement est traité sur Stripe. Aucune clé secrète n'est utilisée dans cette application.</p>
                    </form>
                </section>
            )}

            {view === 'partnership' && (
                <section className="form-layout">
                    <div className="form-intro">
                        <p className="eyebrow">Agir ensemble</p>
                        <h1>Les grandes idées aiment les alliances.</h1>
                        <p>Vous avez un projet, une expertise ou un réseau à mettre en mouvement ? Parlons de ce que nous pouvons construire ensemble.</p>
                    </div>
                    <form className="form-panel" onSubmit={handlePartnershipSubmit}>
                        <div className="form-heading"><span>01</span><h2>Votre proposition</h2></div>
                        <div className="field-grid two-col">
                            <label>Nom complet<input required value={partnership.name} onChange={(event) => updatePartnership('name', event.target.value)} /></label>
                            <label>Adresse e-mail<input required type="email" value={partnership.email} onChange={(event) => updatePartnership('email', event.target.value)} /></label>
                            <label>Organisation<input value={partnership.organization} onChange={(event) => updatePartnership('organization', event.target.value)} /></label>
                            <label>Téléphone<input value={partnership.phone} onChange={(event) => updatePartnership('phone', event.target.value)} /></label>
                        </div>
                        <label>Type de partenariat<select value={partnership.partnership_type} onChange={(event) => updatePartnership('partnership_type', event.target.value)}><option value="collaborative_project">Projet collaboratif</option><option value="financial_partnership">Partenariat financier</option><option value="institutional_partnership">Partenariat institutionnel</option><option value="other">Autre</option></select></label>
                        <label>Votre message<textarea rows={6} value={partnership.message} onChange={(event) => updatePartnership('message', event.target.value)} /></label>
                        <button className="primary-button full-width" disabled={busy}>{busy ? 'Envoi...' : 'Envoyer la proposition →'}</button>
                    </form>
                </section>
            )}

            <footer className="site-footer"><span>Renaissance Muntu</span><span>Elimb'a Dikalo · Montréal</span></footer>
        </main>
    );
}

export default App;

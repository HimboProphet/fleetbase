import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class AuthRhinoIdRoute extends Route {
    @service session;
    @service router;
    @service notifications;

    async beforeModel() {
        const params = new URLSearchParams(window.location.hash.slice(1));
        const authToken = params.get('authToken');
        const identity = params.get('identity');

        window.history.replaceState(null, document.title, window.location.pathname);

        if (!authToken || !identity) {
            this.notifications.error('RHINO ID dispatch handoff is missing a session.');
            return this.router.transitionTo('auth.login');
        }

        try {
            await this.session.authenticate('authenticator:fleetbase', { authToken, identity });
            return this.router.transitionTo('console');
        } catch (error) {
            this.notifications.serverError(error);
            return this.router.transitionTo('auth.login');
        }
    }
}

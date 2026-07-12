import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConsoleAdminPermissionsMatrixRoute extends Route {
    @service fetch;

    model() {
        return this.fetch.get('auth/rhino-id/permissions-matrix');
    }
}

/* jshint esversion: 6 */

/**
 * Nextcloud - suitecrm
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

import Vue from 'vue'
import './bootstrap'
import Dashboard from './views/Dashboard'

document.addEventListener('DOMContentLoaded', function() {

	OCA.Dashboard.register('suitecrm_events', (el, { widget }) => {
		const View = Vue.extend(Dashboard)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})

})

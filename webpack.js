const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
    colors: true,
    excludeModules: true,
}

webpackConfig.entry = {
    personalSettings: { import: path.join(__dirname, 'src', 'personalSettings.js'), filename: 'integration_suitecrm-personalSettings.js' },
    adminSettings: { import: path.join(__dirname, 'src', 'adminSettings.js'), filename: 'integration_suitecrm-adminSettings.js' },
    dashboard: { import: path.join(__dirname, 'src', 'dashboard.js'), filename: 'integration_suitecrm-dashboard.js' },
}

module.exports = webpackConfig

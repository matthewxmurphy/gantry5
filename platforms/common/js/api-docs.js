/* Copyright (C) 2026 Matthew X Murphy https://MatthewxMurphy.com
 * SPDX-License-Identifier: MIT
 * Local Swagger UI: no remote validator, telemetry or persisted credentials.
 */
(function () {
    'use strict';
    var root = document.getElementById('gantry-api-docs');
    var source = document.getElementById('gantry-openapi-spec');
    if (!root || !source || !window.SwaggerUIBundle) { return; }
    window.SwaggerUIBundle({
        spec: JSON.parse(source.textContent),
        dom_id: '#gantry-swagger-ui',
        deepLinking: true,
        validatorUrl: null,
        persistAuthorization: false,
        queryConfigEnabled: false,
        withCredentials: false,
        supportedSubmitMethods: root.dataset.apiAvailable === 'yes' ? ['get', 'post', 'patch', 'put', 'delete'] : [],
        requestInterceptor: function (request) {
            // The shipped specification uses a relative same-origin server.
            // Never forward a manually entered token to another origin.
            if (new URL(request.url, window.location.href).origin !== window.location.origin) {
                throw new Error('Gantry API requests must stay on this CMS origin.');
            }
            request.credentials = 'omit';
            return request;
        }
    });
}());

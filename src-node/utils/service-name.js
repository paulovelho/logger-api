const config = require('../../config.json');

const namesByService = Object.fromEntries(
  config.users.map((u) => [u.service, u.name || u.service])
);

function getServiceName(service) {
  return namesByService[service] || service;
}

module.exports = getServiceName;

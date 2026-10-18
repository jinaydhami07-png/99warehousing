/**
 * The DynamoDB data layer.
 *
 * Services import from here rather than reaching for a client directly, so the
 * set of queries the application can make stays visible in one place — and so
 * the next storage change touches this directory and nothing else.
 */
'use strict';

module.exports = {
  users: require('./user.repo'),
  properties: require('./property.repo'),
  favorites: require('./favorite.repo'),
  enquiries: require('./enquiry.repo'),
  reviews: require('./review.repo'),
  auditlogs: require('./auditlog.repo'),
  images: require('./image.repo'),
  tables: require('./tables').TABLES,
  client: require('./client'),
};

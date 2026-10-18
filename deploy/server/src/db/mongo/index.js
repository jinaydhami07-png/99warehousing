'use strict';
module.exports = {
  users: require('./user.repo'),
  properties: require('./property.repo'),
  favorites: require('./favorite.repo'),
  enquiries: require('./enquiry.repo'),
  reviews: require('./review.repo'),
  auditlogs: require('./auditlog.repo'),
  images: require('./image.repo'),
};

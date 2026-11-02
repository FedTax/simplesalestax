// Journal a possible remote capture only when the saved integration can submit one.
function captureIntentRequired(settings,order,status){
 return settings.disable_integration!=='yes'&&settings.capture_orders_in_taxcloud==='yes'&&['processing','completed'].includes(status)&&[status,'both'].includes(settings.capture_trigger)&&!['captured','partially_refunded','refunded'].includes(order.taxcloudStatus);
}
module.exports={captureIntentRequired};

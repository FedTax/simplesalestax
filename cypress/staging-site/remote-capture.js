// Validation for the core checkout fixture: one package, prices excluding tax.
// Return only reportable fields; never copy connection IDs or API credentials.
function singlePackageCaptureEvidence(remote,order){
  const expectedId=order.remoteId?`${order.remoteId}_0`:'';
  if(!expectedId||remote?.orderId!==expectedId)throw new Error('Remote capture returned a different order ID.');
  if(!remote.completedDate||!Number.isFinite(Date.parse(remote.completedDate)))throw new Error('Remote capture has no valid completed date.');
  if(!Array.isArray(remote.lineItems)||!remote.lineItems.length)throw new Error('Remote capture has no line items.');
  let sales=0,tax=0;
  for(const item of remote.lineItems){
    const price=Number(item.price),quantity=Number(item.quantity),amount=Number(item.tax?.amount);
    if(![price,quantity,amount].every(Number.isFinite)||quantity<=0)throw new Error('Remote capture contains invalid financial values.');
    sales+=price*quantity;tax+=amount;
  }
  const cents=n=>Math.round(n*100);
  if(!Number.isFinite(order.total)||!Number.isFinite(order.tax)||cents(sales+tax)!==cents(order.total)||cents(tax)!==cents(order.tax))throw new Error('Remote capture totals differ from the WooCommerce order.');
  return {remoteId:remote.orderId,completedDate:remote.completedDate,channel:remote.channel??null,sales:cents(sales)/100,tax:cents(tax)/100,total:cents(sales+tax)/100};
}
module.exports={singlePackageCaptureEvidence};

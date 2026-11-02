// A compressed single-purchase package stores a local detail hash, not the remote UUID.
function singlePurchaseMapping(order,certificates,state='MN'){
 const hashes=[...new Set((order.packages||[]).map(p=>p.certificate_id).filter(Boolean))];
 if(!hashes.length||hashes.some(id=>!/^[a-f0-9]{32}$/i.test(id)))throw new Error('Expected the single-purchase detail hash on the saved order.');
 const matches=certificates.filter(c=>c.singlePurchase===true&&!c.disabledAt&&String(c.customerId)===String(order.customerId)&&c.customerName===order.billingName&&(c.states||[]).some(s=>s.abbreviation===state));
 if(matches.length!==1)throw new Error('Single-purchase readback must uniquely match this named order, customer and state.');
 const certificate=matches[0];if(!/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(certificate.certificateId))throw new Error('Remote single-purchase certificate UUID is missing.');
 return {orderId:order.id,localHashes:hashes,certificateId:certificate.certificateId,customerId:certificate.customerId,customerName:certificate.customerName,state,singlePurchase:true,reason:certificate.reason,source:'Read-only certificate lookup following actual checkout submission; dashboard UUID still requires browser verification.'};
}
module.exports={singlePurchaseMapping};

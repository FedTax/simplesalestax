// Cypress CLI normalizes pipe-separated environment values into commas.
function matchesTitle(title,filter){
 if(!filter)return true;
 const normalize=value=>String(value).replace(/\s/g,'');
 return String(filter).split(/[|,]/).filter(Boolean).some(part=>normalize(title).includes(normalize(part)));
}
module.exports={matchesTitle};

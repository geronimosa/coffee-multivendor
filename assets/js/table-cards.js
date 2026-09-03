document.querySelectorAll('[data-table-href]').forEach((card)=>{
  const open=()=>{window.location.href=card.dataset.tableHref;};
  card.addEventListener('click',(event)=>{if(!event.target.closest('a,button,form,input'))open();});
  card.addEventListener('keydown',(event)=>{if((event.key==='Enter'||event.key===' ')&&!event.target.closest('a,button,form,input')){event.preventDefault();open();}});
});

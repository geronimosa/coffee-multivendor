(()=>{
  const params=new URLSearchParams(window.location.hash.slice(1));
  const key=params.get('staff-key');
  const form=document.getElementById('staff-key-form');
  const input=document.getElementById('staff_key');
  if(!key||!form||!input)return;
  history.replaceState(null,'',window.location.pathname+window.location.search);
  input.value=key;
  if(input.checkValidity())form.requestSubmit();
})();

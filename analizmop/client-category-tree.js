/* Lazy category -> top 10 subcategories -> top 20 products, shared by client history entries. */
(() => {
  'use strict';
  let nextId=0;
  window.calltrackCategoryTree={
    render(container,categories,options){
      const {money,number,share,loadDetails,isCurrent,totalRevenue}=options;
      const text=(tag,value)=>{const element=document.createElement(tag);element.textContent=value;return element;};
      function table(rows,level,total,category){
        const root=document.createElement('table');
        const head=document.createElement('thead'),titles=document.createElement('tr');
        const label=level==='categories'?'Категория':level==='subcategories'?'Подкатегория':'Товар';
        for(const value of [label,'Выручка',level==='categories'?'Доля в общей выручке':level==='subcategories'?'Доля в категории':'Доля в подкатегории','Количество товаров'])titles.append(text('th',value));
        head.append(titles);root.append(head);const body=document.createElement('tbody');root.append(body);
        for(const row of rows){
          const tr=document.createElement('tr'),first=document.createElement('td');tr.append(first);body.append(tr);
          const name=level==='categories'?row.category:row.name;
          let button;
          if(level!=='products'){
            button=text('button','▸ '+name);button.type='button';button.className='category-expand';button.setAttribute('aria-expanded','false');first.append(button);
          }else{
            first.append(text('span',name));
            const metadata=[row.article&&'Артикул: '+row.article,row.code&&'Код: '+row.code].filter(Boolean).join(' · ');
            if(metadata){const meta=text('small',metadata);meta.className='history-meta category-product-meta';first.append(meta);}
          }
          tr.append(text('td',money(row.revenue)),text('td',share(row.revenue,total)),text('td',number(row.quantity)));
          if(!button)continue;
          const detailRow=document.createElement('tr'),cell=document.createElement('td'),content=document.createElement('div');
          cell.colSpan=4;content.className='category-children';content.id='category-detail-'+(++nextId);content.setAttribute('aria-live','polite');cell.append(content);detailRow.append(cell);detailRow.hidden=true;body.append(detailRow);
          button.setAttribute('aria-controls',content.id);
          let state='idle';
          const path=level==='categories'?{category:name}:{category,subcategory:name};
          async function load(){
            state='loading';content.replaceChildren(text('p','Загрузка…'));
            try{
              const data=await loadDetails(path);if(!isCurrent()||!container.contains(content))return;
              content.replaceChildren();const childLevel=level==='categories'?'subcategories':'products';
              const title=childLevel==='subcategories'?'Топ 10 подкатегорий':'Топ 20 товаров';
              content.append(text('p',title+' · показано '+data.items.length+' из '+data.total_count));
              if(data.items.length)content.append(table(data.items,childLevel,data.parent_revenue,path.category));
              else content.append(text('p','Данные не найдены'));
              state='loaded';
            }catch(error){
              if(!isCurrent()||!container.contains(content))return;
              state='error';content.replaceChildren(text('p',error.message||'Не удалось загрузить данные'));
              const retry=text('button','Повторить');retry.type='button';retry.className='category-retry';retry.addEventListener('click',load);content.append(retry);
            }
          }
          button.addEventListener('click',()=>{
            detailRow.hidden=!detailRow.hidden;const expanded=!detailRow.hidden;
            button.setAttribute('aria-expanded',String(expanded));button.textContent=(expanded?'▾ ':'▸ ')+name;
            if(expanded&&state==='idle')load();
          });
        }
        return root;
      }
      container.replaceChildren(table(categories,'categories',totalRevenue));
    }
  };
})();

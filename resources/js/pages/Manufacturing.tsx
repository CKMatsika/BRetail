import React, { useState } from "react";
import { router, useForm } from "@inertiajs/react";
import { Factory, Plus, Trash2, Wrench } from "lucide-react";

type Variant = { id:number; sku:string; name:string; cost_price:string; product:{name:string} };
type Recipe = { id:number; name:string; active:boolean; batch_quantity:string; output_variant_id:number; output:Variant; lines:{id:number;variant_id:number;quantity:string;variant:Variant}[] };
type Order = { id:number; reference:string; quantity:string; status:string; material_cost:string|null; notes:string|null; recipe:Recipe; lines:{variant:Variant;quantity:string;unit_cost:string;value:string}[]; warehouse_id:number };
type Props = { recipes:Recipe[]; orders:Order[]; variants:Variant[]; warehouses:{id:number;name:string;branch_id:number}[]; balances:{variant_id:number;warehouse_id:number;quantity:string;average_cost:string}[]; canManage:boolean; canProduce:boolean; baseCurrency:string };
const itemName = (v?:Variant) => v ? `${v.product?.name ?? ""} · ${v.name} (${v.sku})` : "Unknown item";

export default function Manufacturing({ data }: {data:Props}) {
    const [editing, setEditing] = useState<Recipe|null>(null);
    const blank = () => ({name:"",output_variant_id:"",batch_quantity:"1",active:true,lines:[{variant_id:"",quantity:"1"}]});
    const recipeForm = useForm(blank());
    const orderForm = useForm({recipe_id:"",warehouse_id:String(data.warehouses[0]?.id ?? ""),quantity:"1",notes:""});
    const submitRecipe = (e:React.FormEvent) => {
        e.preventDefault();
        const done = () => { recipeForm.reset(); setEditing(null); };
        if (editing) recipeForm.patch(`/manufacturing/recipes/${editing.id}`, {onSuccess:done});
        else recipeForm.post("/manufacturing/recipes", {onSuccess:done});
    };
    const editRecipe = (recipe:Recipe) => {
        setEditing(recipe);
        recipeForm.setData({name:recipe.name,output_variant_id:String(recipe.output_variant_id),batch_quantity:String(recipe.batch_quantity),active:recipe.active,lines:recipe.lines.map(l=>({variant_id:String(l.variant_id),quantity:String(l.quantity)}))});
    };
    const balance = (variantId:number, warehouseId:number) => data.balances.find(b=>b.variant_id===variantId && b.warehouse_id===warehouseId)?.quantity ?? "0";
    const money = (value:string|null|undefined) => new Intl.NumberFormat(undefined,{style:"currency",currency:data.baseCurrency||"USD"}).format(Number(value||0));
    return <div className="page-stack">
        <section className="panel"><div className="panel-heading"><span className="eyebrow">OPERATIONS · INVENTORY</span><h1><Factory size={24}/> Manufacturing & production</h1><p>Define material recipes, schedule production, then post material usage and finished stock together.</p></div>
            <div className="notice"><strong>Material costing:</strong> finished stock receives the value of materials consumed, using current weighted average inventory cost. Labour and overhead are not included yet.</div>
        </section>
        {data.canManage && <section className="panel"><h2>{editing?"Edit recipe":"Production recipes"}</h2><p className="muted">A recipe describes the components needed for one batch of finished items.</p>
            <form className="form-grid" onSubmit={submitRecipe}>
                <label>Recipe name<input required value={recipeForm.data.name} onChange={e=>recipeForm.setData("name",e.target.value)} /></label>
                <label>Finished item<select required value={recipeForm.data.output_variant_id} onChange={e=>recipeForm.setData("output_variant_id",e.target.value)}><option value="">Choose item</option>{data.variants.map(v=><option key={v.id} value={v.id}>{itemName(v)}</option>)}</select></label>
                <label>Batch output quantity<input required min="0.001" step="0.001" type="number" value={recipeForm.data.batch_quantity} onChange={e=>recipeForm.setData("batch_quantity",e.target.value)} /></label>
                <div className="full-width"><h3>Components</h3>{recipeForm.data.lines.map((line,index)=><div className="form-grid" key={index}>
                    <label>Raw material / component<select required value={line.variant_id} onChange={e=>recipeForm.setData("lines",recipeForm.data.lines.map((l,i)=>i===index?{...l,variant_id:e.target.value}:l))}><option value="">Choose component</option>{data.variants.filter(v=>String(v.id)!==recipeForm.data.output_variant_id).map(v=><option key={v.id} value={v.id}>{itemName(v)}</option>)}</select></label>
                    <label>Quantity per batch<input required min="0.001" step="0.001" type="number" value={line.quantity} onChange={e=>recipeForm.setData("lines",recipeForm.data.lines.map((l,i)=>i===index?{...l,quantity:e.target.value}:l))}/></label>
                    {recipeForm.data.lines.length>1&&<button type="button" className="secondary" onClick={()=>recipeForm.setData("lines",recipeForm.data.lines.filter((_,i)=>i!==index))}><Trash2 size={16}/> Remove</button>}
                </div>)}<button type="button" className="secondary" onClick={()=>recipeForm.setData("lines",[...recipeForm.data.lines,{variant_id:"",quantity:"1"}])}><Plus size={16}/> Add component</button></div>
                <div className="form-actions"><button type="submit" disabled={recipeForm.processing}>{editing?"Save recipe":"Create recipe"}</button>{editing&&<button type="button" className="secondary" onClick={()=>{setEditing(null);recipeForm.reset()}}>Cancel edit</button>}</div>
            </form>
        </section>}
        <section className="panel"><h2>Recipes</h2>{data.recipes.length?<div className="table-wrap"><table><thead><tr><th>Recipe</th><th>Finished item</th><th>Batch size</th><th>Components</th>{data.canManage&&<th/>}</tr></thead><tbody>{data.recipes.map(r=><tr key={r.id}><td>{r.name}{!r.active&&<small> · Inactive</small>}</td><td>{itemName(r.output)}</td><td>{r.batch_quantity}</td><td>{r.lines.map(l=>`${itemName(l.variant)} × ${l.quantity}`).join(", ")}</td>{data.canManage&&<td><button className="secondary" onClick={()=>editRecipe(r)}>Edit</button></td>}</tr>)}</tbody></table></div>:<p className="muted">No recipes yet. Add a recipe above to describe the materials used to make one batch.</p>}</section>
        {data.canManage&&<section className="panel"><h2>Plan production</h2><form className="form-grid" onSubmit={e=>{e.preventDefault();orderForm.post("/manufacturing/orders",{onSuccess:()=>orderForm.reset("quantity","notes")})}}>
            <label>Recipe<select required value={orderForm.data.recipe_id} onChange={e=>orderForm.setData("recipe_id",e.target.value)}><option value="">Choose recipe</option>{data.recipes.filter(r=>r.active).map(r=><option key={r.id} value={r.id}>{r.name} — {itemName(r.output)}</option>)}</select></label>
            <label>Production warehouse<select required value={orderForm.data.warehouse_id} onChange={e=>orderForm.setData("warehouse_id",e.target.value)}>{data.warehouses.map(w=><option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
            <label>Quantity to produce<input required type="number" min="0.001" step="0.001" value={orderForm.data.quantity} onChange={e=>orderForm.setData("quantity",e.target.value)}/></label>
            <label>Notes<input value={orderForm.data.notes} onChange={e=>orderForm.setData("notes",e.target.value)} /></label>
            <button type="submit" disabled={orderForm.processing||!data.recipes.length}><Wrench size={16}/> Create production order</button>
        </form></section>}
        <section className="panel"><h2>Production orders</h2>{data.orders.length?<div className="table-wrap"><table><thead><tr><th>Reference</th><th>Product / recipe</th><th>Quantity</th><th>Status</th><th>Material cost</th><th>Warehouse</th><th>Actions</th></tr></thead><tbody>{data.orders.map(o=><tr key={o.id}><td>{o.reference}</td><td>{itemName(o.recipe?.output)}<small>{o.recipe?.name}</small></td><td>{o.quantity}</td><td><span className="status-pill">{o.status}</span></td><td>{o.status==="completed"?money(o.material_cost):"—"}</td><td>{data.warehouses.find(w=>w.id===o.warehouse_id)?.name??"—"}</td><td>{o.status==="planned"&&<>{data.canProduce&&<button onClick={()=>router.post(`/manufacturing/orders/${o.id}/complete`)}>Complete</button>}{data.canManage&&<button className="secondary" onClick={()=>router.post(`/manufacturing/orders/${o.id}/cancel`)}>Cancel</button>}</>}</td></tr>)}</tbody></table></div>:<p className="muted">Production orders will appear here.</p>}</section>
    </div>;
}

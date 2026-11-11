<?php

namespace App\Services\Tender;

use App\Models\ProductVariant;
use App\Models\Company;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\TenderPriceSource;
use App\Models\TenderPricingEstimate;
use App\Models\TenderPricingItem;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenderPriceSourcing
{
    public function inventory(User $user, Tender $tender, TenderPricingItem $item): array
    {
        $tokens = $this->tokens($item->description.' '.$item->unit_of_measure);
        if (! $tokens) return [];
        $primary = Company::query()->value('base_currency') ?: 'USD';
        $variants = ProductVariant::with('product')->where('active',true)->whereHas('product',fn($q)=>$q->where('active',true)->where('item_type','stock'))->get();
        $suggestions = [];
        foreach ($variants as $variant) {
            $product = $variant->product;
            if ((float)$variant->cost_price<=0) continue;
            $catalogText = $product->name.' '.$variant->name.' '.$variant->sku.' '.$product->description.' '.implode(' ',array_values($product->attributes ?? [])).' '.implode(' ',array_values($variant->attributes ?? []));
            $other = $this->tokens($catalogText);
            $overlap = count(array_intersect($tokens,$other));
            $score = round(100*$overlap/max(1,count($tokens)),1);
            if ($score < 15) continue;
            $source = $this->saveCandidate($user,$tender,$item,[
                'source_type'=>'inventory','source_label'=>trim($product->name.' · '.$variant->name.' ('.$variant->sku.')'),
                'unit_cost'=>$variant->cost_price,'currency'=>$primary,'inventory_variant_id'=>$variant->id,
                'source_url'=>'/inventory',
                'confidence'=>$score,'match_reason'=>"Catalog cost in company base currency. Matched {$overlap} of ".count($tokens)." specification words against the inventory item name, SKU and attributes. Verify technical compliance before accepting.",
            ]);
            $suggestions[] = $source;
        }
        usort($suggestions,fn($a,$b)=>(float)$b->confidence <=>(float)$a->confidence);
        return array_slice($suggestions,0,12);
    }

    public function searchWeb(User $user, Tender $tender, TenderPricingItem $item): array
    {
        $this->requireAi();
        $currency = TenderPricingEstimate::where('tender_id',$tender->id)->value('primary_currency') ?: $tender->currency ?: 'USD';
        $prompt = "Use Google Search to find current publicly listed prices for this tender BOQ item in {$currency}. Return at most five actual offers with exact product name, supplier, price per {$item->unit_of_measure}, currency, unit/basis (VAT included/excluded if stated), and the exact source URL. Prefer Zimbabwean suppliers, then regional ones. If no verifiable price is listed, return an empty offers array. Do not estimate or invent a price.\nItem description/specification: {$item->description}\nQuantity needed: {$item->quantity}\nReturn JSON only: {\"offers\":[{\"product\":\"\",\"supplier\":\"\",\"price\":0,\"currency\":\"{$currency}\",\"unit\":\"\",\"basis\":\"\",\"url\":\"\",\"confidence\":0}]}";
        $response = $this->gemini($prompt,true);
        $offers = $response['data']['offers'] ?? [];
        $chunks = $response['grounding_chunks'];
        $sources = [];
        foreach (is_array($offers) ? $offers : [] as $offer) {
            $url = $offer['url'] ?? '';
            $grounded = collect($chunks)->first(fn($chunk)=>($chunk['uri']??null)===$url);
            if (! $grounded || ! in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true) || ! is_numeric($offer['price'] ?? null) || (float)$offer['price']<=0) continue;
            $sources[] = $this->saveCandidate($user,$tender,$item,[
                'source_type'=>'web','source_label'=>trim(($offer['product']??$item->description).' · '.($offer['supplier']??$grounded['title']??'Web listing')),
                'unit_cost'=>$offer['price'],'currency'=>strtoupper(substr((string)($offer['currency']??$currency),0,3)),
                'source_url'=>$url,'confidence'=>max(0,min(100,(float)($offer['confidence']??50))),
                'match_reason'=>trim(($offer['basis']??'Web-listed price; confirm tax, delivery and unit basis.').' '.($offer['unit']??'')),
            ]);
        }
        return ['sources'=>$sources,'queries'=>$response['search_queries']];
    }

    public function uploadQuote(User $user, Tender $tender, TenderPricingItem $item, UploadedFile $file): array
    {
        $document = app(TenderService::class)->uploadDocument($user,$tender->id,$file,['document_type'=>'supplier_quote','description'=>'Supplier quotation uploaded from BOQ price sourcing.']);
        $path = Storage::disk('local')->path($document->file_path);
        $extracted = app(DocumentTextExtractor::class)->extract($path,$document->mime_type,$document->file_name);
        if (strlen(trim($extracted['text'])) < 30) return ['document'=>$document,'sources'=>[],'message'=>'Quote uploaded, but readable text could not be extracted. Check that it is not a scanned image or password-protected PDF.'];
        if (! config('services.gemini.key')) return ['document'=>$document,'sources'=>[],'message'=>'Quote uploaded. Configure GEMINI_API_KEY to extract line prices from supplier quotes.'];
        $prompt = "Extract actual supplier offers from the attached quotation text that match this BOQ requirement. Return only offers whose product/specification is plausibly the same. Never infer a missing price. Keep the quoted currency and unit.\nBOQ requirement: {$item->description}\nBOQ unit: {$item->unit_of_measure}\nQuotation filename: {$document->file_name}\nQUOTATION TEXT:\n".mb_substr($extracted['text'],0,30000)."\nReturn JSON only: {\"offers\":[{\"product\":\"\",\"supplier\":\"\",\"price\":0,\"currency\":\"USD\",\"unit\":\"\",\"basis\":\"\",\"confidence\":0}]}";
        $response = $this->gemini($prompt,false);
        $defaultCurrency = TenderPricingEstimate::where('tender_id',$tender->id)->value('primary_currency') ?: $tender->currency ?: 'USD';
        $sources=[];
        foreach (is_array($response['data']['offers']??null)?$response['data']['offers']:[] as $offer) {
            if (!is_numeric($offer['price']??null)||(float)$offer['price']<=0) continue;
            $sources[]=$this->saveCandidate($user,$tender,$item,[
                'source_type'=>'supplier_quote','source_label'=>trim(($offer['supplier']??'Supplier').' · '.($offer['product']??$document->file_name)),
                'unit_cost'=>$offer['price'],'currency'=>strtoupper(substr((string)($offer['currency']??$defaultCurrency),0,3)),
                'source_document_id'=>$document->id,'source_url'=>'/tenders/documents/'.$document->id.'/download',
                'confidence'=>max(0,min(100,(float)($offer['confidence']??50))),
                'match_reason'=>trim(($offer['basis']??'Extracted from uploaded supplier quotation.').' '.($offer['unit']??'')),
            ]);
        }
        return ['document'=>$document,'sources'=>$sources,'message'=>$sources?'Quote analyzed; verify extracted values against the uploaded document.':'Quote uploaded, but no matching priced line was extracted.'];
    }

    public function decide(User $user,TenderPriceSource $source,string $decision): TenderPriceSource
    {
        abort_unless(in_array($decision,['approve','reject'],true),422);
        return DB::transaction(function()use($user,$source,$decision){
            $source=TenderPriceSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->status==='pending',422,'This price source has already been reviewed.');
            if ($decision==='reject') {
                $source->update(['status'=>'rejected','reviewed_by_user_id'=>$user->id,'reviewed_at'=>now()]);
                Audit::record('tender.price_source_rejected',(string)$source->id,['tender_id'=>$source->tender_id,'source_type'=>$source->source_type]);
                return $source->fresh();
            }
            $item=TenderPricingItem::whereKey($source->pricing_item_id)->lockForUpdate()->firstOrFail();
            $estimate=$item->estimate;
            abort_unless(strtoupper($source->currency)===strtoupper($estimate->primary_currency),422,'Convert this source into '.$estimate->primary_currency.' before accepting it.');
            app(TenderPricingService::class)->updateItem($item,['unit_cost'=>$source->unit_cost]);
            $item->update([
                'source_type'=>$source->source_type,'source_label'=>$source->source_label,'source_url'=>$source->source_url,
                'source_document_id'=>$source->source_document_id,'inventory_variant_id'=>$source->inventory_variant_id,'source_verified_at'=>now(),
            ]);
            $source->update(['status'=>'approved','reviewed_by_user_id'=>$user->id,'reviewed_at'=>now()]);
            Audit::record('tender.price_source_approved',(string)$source->id,['tender_id'=>$source->tender_id,'pricing_item_id'=>$item->id,'unit_cost'=>$source->unit_cost,'currency'=>$source->currency]);
            return $source->fresh();
        },3);
    }

    private function saveCandidate(User $user,Tender $tender,TenderPricingItem $item,array $data): TenderPriceSource
    {
        $key=hash('sha256',implode('|',[$item->id,$data['source_type'],$data['source_label'],$data['unit_cost'],$data['currency'],$data['source_url']??'',$data['source_document_id']??'',$data['inventory_variant_id']??'']));
        return TenderPriceSource::firstOrCreate(['candidate_key'=>$key],['tenant_id'=>$user->tenant_id,'tender_id'=>$tender->id,'pricing_item_id'=>$item->id,'source_type'=>$data['source_type'],'source_label'=>$data['source_label'],'unit_cost'=>$data['unit_cost'],'currency'=>$data['currency'],'source_url'=>$data['source_url']??null,'source_document_id'=>$data['source_document_id']??null,'inventory_variant_id'=>$data['inventory_variant_id']??null,'confidence'=>$data['confidence']??null,'match_reason'=>$data['match_reason']??null,'status'=>'pending']);
    }

    private function tokens(string $text): array
    {
        $words=preg_split('/[^a-z0-9]+/i',Str::lower($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
        return array_values(array_unique(array_filter($words,fn($word)=>strlen($word)>2&&!in_array($word,['the','and','for','with','per','set','lot','unit','each','supply','item','including','minimum','required'],true))));
    }

    private function requireAi(): void
    {
        if (! config('services.gemini.key')) throw ValidationException::withMessages(['ai'=>'Configure GEMINI_API_KEY to search live web pricing. Google Search grounding is billed by Google; search runs only when you request it.']);
    }

    private function gemini(string $prompt,bool $search): array
    {
        $key=config('services.gemini.key');
        $models=array_unique(array_filter(['gemini-2.5-flash',config('services.gemini.model'),'gemini-2.0-flash']));
        foreach($models as $model) {
            try {
                $payload=['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0.1]];
                if($search)$payload['tools']=[['google_search'=>new \stdClass()]];
                else $payload['generationConfig']['responseMimeType']='application/json';
                $response=Http::timeout(60)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".urlencode($key),$payload);
                if(!$response->successful())continue;
                $body=$response->json();
                $raw=trim($body['candidates'][0]['content']['parts'][0]['text']??'');
                $raw=preg_replace('/^```(?:json)?\s*|\s*```$/','',$raw)??$raw;
                $decoded=json_decode($raw,true);
                $metadata=$body['candidates'][0]['groundingMetadata']??[];
                $chunks=[];
                foreach(($metadata['groundingChunks']??[]) as $chunk) if(isset($chunk['web']['uri']))$chunks[]=['uri'=>$chunk['web']['uri'],'title'=>$chunk['web']['title']??$chunk['web']['uri']];
                return ['data'=>is_array($decoded)?$decoded:[],'grounding_chunks'=>$chunks,'search_queries'=>$metadata['webSearchQueries']??[]];
            } catch(\Throwable) { continue; }
        }
        throw ValidationException::withMessages(['ai'=>'The Gemini request did not return usable pricing data. Check the configured model and API access, then try again.']);
    }
}

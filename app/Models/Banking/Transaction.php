<?php

namespace App\Models\Banking;

use App\Abstracts\Model;
use App\Models\Setting\Category;
use App\Traits\Currencies;
use App\Traits\DateTime;
use App\Traits\Media;
use App\Traits\Recurring;
use App\Traits\Transactions;
use Bkwld\Cloner\Cloneable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use Cloneable, Currencies, DateTime, HasFactory, Media, Recurring, Transactions;

    protected $table = 'transactions';

    protected $dates = ['deleted_at', 'paid_at'];

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['company_id', 'type', 'account_id', 'paid_at', 'amount', 'currency_code', 'currency_rate', 'document_id', 'contact_id', 'description', 'category_id', 'payment_method', 'reference', 'parent_id'];

    /**
     * Sortable columns.
     *
     * @var array
     */
    public $sortable = ['paid_at', 'amount','category.name', 'account.name'];

    /**
     * Clonable relationships.
     *
     * @var array
     */
    public $cloneable_relations = ['recurring'];

    public function account()
    {
        return $this->belongsTo('App\Models\Banking\Account')->withDefault(['name' => trans('general.na')]);
    }

    public function bill()
    {
        return $this->belongsTo('App\Models\Purchase\Bill', 'document_id');
    }

    public function category()
    {
        return $this->belongsTo('App\Models\Setting\Category')->withDefault(['name' => trans('general.na')]);
    }

    public function contact()
    {
        return $this->belongsTo('App\Models\Common\Contact')->withDefault(['name' => trans('general.na')]);
    }

    public function currency()
    {
        return $this->belongsTo('App\Models\Setting\Currency', 'currency_code', 'code');
    }

    public function invoice()
    {
        return $this->belongsTo('App\Models\Sale\Invoice', 'document_id');
    }

    public function recurring()
    {
        return $this->morphOne('App\Models\Common\Recurring', 'recurable');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\Auth\User', 'contact_id', 'id');
    }

    /**
     * Scope to only include contacts of a given type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param mixed $types
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeType($query, $types)
    {
        if (empty($types)) {
            return $query;
        }

        return $query->whereIn($this->table . '.type', (array) $types);
    }

    /**
     * Scope to include only income.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIncome($query)
    {
        return $query->whereIn($this->table . '.type', (array) $this->getIncomeTypes());
    }

    /**
     * Scope to include only expense.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpense($query)
    {
        return $query->whereIn($this->table . '.type', (array) $this->getExpenseTypes());
    }

    /**
     * Get only transfers.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsTransfer($query)
    {
        return $query->where('category_id', '=', Category::transfer());
    }

    /**
     * Skip transfers.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsNotTransfer($query)
    {
        return $query->where('category_id', '<>', Category::transfer());
    }

    /**
     * Get only documents (invoice/bill).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsDocument($query)
    {
        return $query->whereNotNull('document_id');
    }

    /**
     * Get only transactions (revenue/payment).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsNotDocument($query)
    {
        return $query->whereNull('document_id');
    }

    /**
     * Get by document (invoice/bill).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param  integer $document_id
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDocument($query, $document_id)
    {
        return $query->where('document_id', '=', $document_id);
    }

    /**
     * Order by paid date.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('paid_at', 'desc');
    }

    /**
     * Scope paid invoice.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePaid($query)
    {
        return $query->sum('amount');
    }

    /**
     * Get only reconciled.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsReconciled($query)
    {
        return $query->where('reconciled', 1);
    }

    /**
     * Get only not reconciled.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsNotReconciled($query)
    {
        return $query->where('reconciled', 0);
    }

    public function onCloning($src, $child = null)
    {
        $this->document_id = null;
    }

    /**
     * Convert amount to double.
     *
     * @param  string  $value
     * @return void
     */
    public function setAmountAttribute($value)
    {
        $this->attributes['amount'] = (double) $value;
    }

    /**
     * Convert currency rate to double.
     *
     * @param  string  $value
     * @return void
     */
    public function setCurrencyRateAttribute($value)
    {
        $this->attributes['currency_rate'] = (double) $value;
    }

    /**
     * Convert amount to double.
     *
     * @return float
     */
    public function getAmountForAccountAttribute()
    {
        $amount = $this->amount;

        // Convert amount if not same currency
        if ($this->account->currency_code != $this->currency_code) {
            $to_code = $this->account->currency_code;
            $to_rate = config('money.' . $this->account->currency_code . '.rate');

            $amount = $this->convertBetween($amount, $this->currency_code, $this->currency_rate, $to_code, $to_rate);
        }

        return $amount;
    }

    /**
     * Get the current balance.
     *
     * @return string
     */
    public function getAttachmentAttribute($value)
    {
        if (!empty($value) && !$this->hasMedia('attachment')) {
            return $value;
        } elseif (!$this->hasMedia('attachment')) {
            return false;
        }

        return $this->getMedia('attachment')->last();
    }

    /**
     * Get the title of type.
     *
     * @return string
     */
    public function getTypeTitleAttribute($value)
    {
        return $value ?? trans_choice('general.' . Str::plural($this->type), 1);
    }

    /**
     * Get the route name.
     *
     * @return string
     */
    public function getRouteNameAttribute($value)
    {
        if ($value) {
            return $value;
        }

        if ($this->isIncome()) {
            return !empty($this->document_id) ? 'invoices.show' : 'revenues.edit';
        }

        if ($this->isExpense()) {
            return !empty($this->document_id) ? 'bills.show' : 'payments.edit';
        }

        return 'transactions.index';
    }

    /**
     * Get the route id.
     *
     * @return string
     */
    public function getRouteIdAttribute($value)
    {
        return $value ?? $this->document_id ?? $this->id;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Database\Factories\Transaction::new();
    }
}

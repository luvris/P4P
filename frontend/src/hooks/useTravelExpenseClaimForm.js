import { useCallback, useEffect, useState } from 'react';
import { travelExpenseClaimService } from '../services/travelExpenseClaimService';
import useFiscalYear from './useFiscalYear';
import { AMOUNT_FIELDS, rowTotal, toAmount } from '../utils/travelExpense';

/**
 * ฟอร์มสร้าง/แก้ไขใบเบิกค่าใช้จ่าย
 *
 * ปีงบประมาณมาจาก Header (read-only) — ไม่ hardcode
 * @param {number|string|null} claimId - ระบุ = โหมดแก้ไข/ดู, null = สร้างใหม่
 */
const useTravelExpenseClaimForm = (claimId = null) => {
    const { fiscalYear } = useFiscalYear();

    const [options, setOptions] = useState(null);
    const [claim, setClaim] = useState(null);
    const [form, setForm] = useState({
        claim_period: '',
        expense_category: '',
        organization_name: '',
        note: '',
    });
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});

    const status = claim?.status ?? 'draft';
    const readOnly = Boolean(claim) && !claim.is_editable;

    // โหลด options ของปีงบที่เลือก (เดือน, ประเภทเริ่มต้น, ชื่อหน่วยงาน)
    useEffect(() => {
        let active = true;
        (async () => {
            try {
                const response = await travelExpenseClaimService.getOptions({ fiscal_year: fiscalYear });
                if (!active) return;
                setOptions(response.data);
                setForm((prev) => ({
                    ...prev,
                    expense_category: prev.expense_category || response.data.default_expense_category,
                    organization_name: prev.organization_name || response.data.organization_name || '',
                }));
            } catch {
                if (active) setError('ไม่สามารถโหลดค่าเริ่มต้นของฟอร์มได้');
            }
        })();
        return () => {
            active = false;
        };
    }, [fiscalYear]);

    // โหลดเอกสารเดิมเมื่อเป็นโหมดแก้ไข/ดู
    useEffect(() => {
        if (!claimId) return undefined;

        let active = true;
        setLoading(true);
        (async () => {
            try {
                const response = await travelExpenseClaimService.getClaim(claimId);
                if (!active) return;
                const data = response.data;
                setClaim(data);
                setForm({
                    claim_period: data.claim_period || '',
                    expense_category: data.expense_category || '',
                    organization_name: data.organization_name || '',
                    note: data.note || '',
                });
                setItems(data.items || []);
            } catch (err) {
                if (active) setError(err.response?.data?.message || 'ไม่พบเอกสารใบเบิกนี้');
            } finally {
                if (active) setLoading(false);
            }
        })();
        return () => {
            active = false;
        };
    }, [claimId]);

    const setField = useCallback((key, value) => {
        setForm((prev) => ({ ...prev, [key]: value }));
        setFieldErrors((prev) => ({ ...prev, [key]: undefined }));
    }, []);

    /** เพิ่มผู้เบิกจาก modal — ข้ามคนที่มีอยู่แล้วในเอกสาร */
    const addEmployees = useCallback((employees) => {
        setItems((prev) => {
            const existing = new Set(prev.map((item) => item.employee_id));
            const additions = employees
                .filter((emp) => !existing.has(emp.id))
                .map((emp) => ({
                    employee_id: emp.id,
                    pid: emp.pid,
                    citizen_id: emp.citizen_id,
                    first_name: emp.first_name,
                    last_name: emp.last_name,
                    position_name: emp.position_name,
                    allowance_amount: 0,
                    accommodation_amount: 0,
                    transportation_amount: 0,
                    other_amount: 0,
                }));
            return [...prev, ...additions];
        });
    }, []);

    const removeItem = useCallback((index) => {
        setItems((prev) => prev.filter((_, i) => i !== index));
    }, []);

    const setItemAmount = useCallback((index, field, value) => {
        if (!AMOUNT_FIELDS.includes(field)) return;
        setItems((prev) =>
            prev.map((item, i) => (i === index ? { ...item, [field]: value } : item)),
        );
    }, []);

    /** ยอดรวมท้ายตาราง — คำนวณจากข้อมูลในฟอร์มทันที */
    const totals = items.reduce(
        (acc, item) => {
            AMOUNT_FIELDS.forEach((field) => {
                acc[field] += toAmount(item[field]);
            });
            acc.total_amount += rowTotal(item);
            return acc;
        },
        {
            allowance_amount: 0,
            accommodation_amount: 0,
            transportation_amount: 0,
            other_amount: 0,
            total_amount: 0,
        },
    );

    const save = useCallback(async (nextStatus = 'draft') => {
        setSaving(true);
        setError('');
        setFieldErrors({});

        try {
            const payload = {
                fiscal_year: fiscalYear,
                claim_period: form.claim_period,
                expense_category: form.expense_category,
                organization_name: form.organization_name || null,
                note: form.note || null,
                status: nextStatus,
                items: items.map((item) => ({
                    employee_id: item.employee_id ?? null,
                    pid: item.pid ?? null,
                    first_name: item.first_name,
                    last_name: item.last_name,
                    position_name: item.position_name ?? null,
                    allowance_amount: toAmount(item.allowance_amount),
                    accommodation_amount: toAmount(item.accommodation_amount),
                    transportation_amount: toAmount(item.transportation_amount),
                    other_amount: toAmount(item.other_amount),
                })),
            };

            const response = claimId
                ? await travelExpenseClaimService.update(claimId, payload)
                : await travelExpenseClaimService.create(payload);

            setClaim(response.data);
            setItems(response.data.items || []);
            return { success: true, data: response.data, message: response.message };
        } catch (err) {
            const message = err.response?.data?.message || 'ไม่สามารถบันทึกเอกสารได้';
            setError(message);
            setFieldErrors(err.response?.data?.errors || {});
            return { success: false, error: message };
        } finally {
            setSaving(false);
        }
    }, [claimId, fiscalYear, form, items]);

    return {
        fiscalYear,
        options,
        claim,
        status,
        readOnly,
        form,
        setField,
        items,
        addEmployees,
        removeItem,
        setItemAmount,
        totals,
        loading,
        saving,
        error,
        fieldErrors,
        save,
    };
};

export default useTravelExpenseClaimForm;
